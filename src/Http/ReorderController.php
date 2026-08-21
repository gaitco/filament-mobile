<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Gait\FilamentMobile\Introspection\HeadlessTableHost;
use Gait\FilamentMobile\Introspection\ReorderDeclaration;
use Gait\FilamentMobile\ResourceRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `POST {resource}/reorder` — the non-pivot branch of Filament's own
 * `CanReorderRecords::reorderTable()`
 * (vendor/filament/tables/src/Concerns/CanReorderRecords.php:18-61), mirrored
 * exactly: `callBeforeReordering()`, one `whereIn()->update([column =>
 * CASE-expression])` inside `DB::transaction()`, then `callAfterReordering()`
 * outside it. The `BelongsToMany` pivot branch (:29-46 of that file) is a
 * P18 non-goal — `ReorderDeclaration::for()` already reads a dotted reorder
 * column as "not reorderable", so this controller never sees that shape.
 *
 * Contains no Livewire symbol, and must not — same discipline as
 * StateController/OptionsController: `HeadlessTableHost::tableFor()` is the
 * one seam that reaches for Filament Tables' own `Table` class.
 *
 * Known divergence: both the membership check and the UPDATE below query
 * through `$class::getEloquentQuery()` (the resource's own base query —
 * scopes, soft deletes, tenancy), not through the table's own `getQuery()`,
 * which additionally applies any `->modifyQueryUsing()` the table declares.
 * `HeadlessTableHost` cannot build that query headlessly — its model
 * resolves to null outside Livewire (see that class's docblock) — so a
 * table-level `modifyQueryUsing` scope is not honoured here even though the
 * resource's own `getEloquentQuery()` scope is.
 */
final class ReorderController
{
    public function __construct(private readonly ResourceRegistry $registry)
    {
    }

    public function __invoke(Request $request, string $resource): JsonResponse
    {
        // Same order as every other endpoint: resource resolution (404)
        // before the Gate (403), so a resource nobody serves never leaks its
        // absence as a permission error.
        [$class] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // Byte-identical to the 404 above, deliberately — not the 422
        // `?reorder=1` (ReorderListTest) uses. A resource that exists but
        // never opted into ->reorderable() must be indistinguishable from
        // one this panel has never heard of, or a caller could probe which
        // resource keys exist versus which merely can't be dragged.
        $declaration = ReorderDeclaration::for($class)
            ?? abort(404, "No mobile resource [{$resource}].");

        abort_unless(ReorderDeclaration::authorizes($class, $request), 403);

        $order = $request->input('order');

        abort_unless(is_array($order) && array_is_list($order), 422, '[order] must be a list.');
        abort_if($order === [], 422, '[order] must not be empty.');

        foreach ($order as $entry) {
            // int|string only, not is_scalar(): a bool survives whereIn() as
            // 0/1 on most drivers, which would silently match a real id
            // rather than fail the way a bona fide bad entry should.
            abort_unless(is_int($entry) || is_string($entry), 422, '[order] entries must be an int or string id.');
        }

        abort_if(
            count($order) !== count(array_unique($order, SORT_REGULAR)),
            422,
            '[order] must not contain duplicates.',
        );

        $model = new ($class::getModel())();
        $keyName = $model->getKeyName();
        $routeKeyName = $model->getRouteKeyName();

        // The resource's own query, not the raw model: a soft delete, a
        // global scope or a tenant boundary must narrow which route keys
        // resolve here exactly as it narrows every other endpoint's lookups.
        // Selected unconditionally through both columns, even when they're
        // the same column, so one code path serves a custom route key and
        // the ordinary case alike.
        $found = $class::getEloquentQuery()
            ->whereIn($routeKeyName, $order)
            ->pluck($keyName, $routeKeyName);

        // Resolved PER KEY with an explicit miss, not by comparing counts.
        // The collection is keyed by the DB's own value for $routeKeyName —
        // whereIn() can match a submitted key loosely (SQLite/MySQL numeric-
        // affinity coercion turns "01" into 1; a case-insensitive collation
        // matches "Foo" to a stored "foo"), so the count can agree while a
        // literal PHP array lookup on the submitted string still misses.
        // ->get() with a null-coalescing abort() turns that miss into the
        // same 422 the endpoint already gives an outright-nonexistent id,
        // rather than an "Undefined array key" 500 from client input.
        $primaryKeyOrder = array_map(
            static fn (mixed $routeKey): mixed => $found->get($routeKey) ?? abort(422, 'Unknown record in order.'),
            $order,
        );

        $table = HeadlessTableHost::tableFor($class);

        // Filament's own hook, given the PRIMARY-key order — the same shape
        // reorderTable() itself hands it (Filament's own sortable list keys
        // its DOM entries by model key, not by a custom route key).
        $table->callBeforeReordering($primaryKeyOrder);

        $connection = $model->getConnection();
        $wrappedKey = $connection->getQueryGrammar()->wrap($keyName);

        DB::transaction(function () use ($class, $keyName, $primaryKeyOrder, $declaration, $wrappedKey, $connection): void {
            $class::getEloquentQuery()
                ->whereIn($keyName, $primaryKeyOrder)
                ->update([
                    $declaration->column => $this->reorderExpression(
                        $primaryKeyOrder,
                        $wrappedKey,
                        $connection,
                        $declaration->direction,
                    ),
                ]);
        });

        // Outside the transaction — Filament's own placement
        // (CanReorderRecords.php:60), not a choice made here: a hook that
        // throws at this point does not roll back a write that has already
        // committed.
        $table->callAfterReordering($primaryKeyOrder);

        // Filament's own reorder has no notification text reachable
        // headlessly (it fires through a Livewire `Notification`, which this
        // package does not host) — this is a plain, package-owned message,
        // not a mirror of one.
        return response()->json(['message' => 'Reordered.']);
    }

    /**
     * Filament's own CASE expression, kept byte-for-byte —
     * vendor/filament/tables/src/Concerns/CanReorderRecords.php:66-77
     * (`makeTableReorderColumnExpression`). `$connection->escape($recordKey)`
     * for the id literal is Filament's own choice, not this package's: left
     * exactly as vendored, rather than parameter-bound, so a Filament upgrade
     * that changes how it escapes an id cannot silently diverge from what
     * ships here.
     *
     * @param  list<int|string>  $order  primary keys, already resolved from route keys
     */
    private function reorderExpression(
        array $order,
        string $wrappedKeyColumn,
        Connection $connection,
        string $direction,
    ): Expression {
        return new Expression(
            'case ' . collect($order)
                ->when(
                    $direction === 'desc',
                    fn (Collection $order): Collection => $order->reverse()->values(),
                )
                ->map(fn ($recordKey, int $recordIndex): string => 'when ' . $wrappedKeyColumn . ' = ' . $connection->escape($recordKey) . ' then ' . ($recordIndex + 1))
                ->implode(' ') . ' end'
        );
    }
}
