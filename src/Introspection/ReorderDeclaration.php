<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Illuminate\Http\Request;

/**
 * The table's `->reorderable()` declaration, read headlessly. The Spatie
 * trait is NOT the capability — Filament's own reorder writes the column
 * directly and never calls setNewOrder() — so this reads exactly what the
 * web panel reads: the column, the direction, `->reorderable()`'s own
 * `condition`, and the `authorizeReorder()` closure.
 *
 * A dotted column is a BelongsToMany pivot reorder (Filament's other branch);
 * that is a P18 non-goal, so it reads as "not reorderable" and the doctor
 * names it.
 *
 * `for()` only ever checks the COLUMN (declared, not dotted) — never the
 * `condition`. A declared-but-`condition: false` column is NOT distinguished
 * from an authorizeReorder() denial: both fold into `authorizes()`'s single
 * `isReorderable()` call below, Filament's own public gate
 * (`filled(column) && evaluate($condition) && isReorderAuthorized()`,
 * `CanReorderRecords.php:104`), and both read on the write endpoint as the
 * SAME 403 — never a 404. Filament's own `reorderTable()` makes no such
 * distinction either (it just returns on `!isReorderable()`), and reaching
 * for one here would mean reflecting a PRIVATE Filament property with no
 * public accessor — a fragility this package won't take on for a status-code
 * nicety `isReorderable()` doesn't offer.
 */
final class ReorderDeclaration
{
    /**
     * One throwaway `Table` per resource class, reused by both `for()` and
     * `authorizes()` within the same request instead of building it twice —
     * `HeadlessTableHost::tableFor()` re-runs the resource's whole `table()`
     * closure every call. Keyed by class-string, never reset: `table()` is a
     * static method with no per-test or per-request variation (a class
     * always declares the same reorder column, condition and authorizer), so
     * unlike a real value cache there is no staleness to flush — a
     * worker-lifetime static (Octane) answers the same question a fresh one
     * would, just without rebuilding it.
     *
     * Untyped (`object`, not Filament's own Table class) deliberately: this
     * file is NOT one of `ArchitectureTest`'s three named exceptions, so it
     * must never import or name-hint that Filament Tables class itself —
     * only call through `HeadlessTableHost`, which does that on this file's
     * behalf.
     *
     * @var array<class-string, object>
     */
    private static array $tables = [];

    public function __construct(
        public readonly string $column,
        public readonly string $direction,
    ) {
    }

    /** @param class-string $resourceClass */
    private static function table(string $resourceClass): object
    {
        return self::$tables[$resourceClass] ??= HeadlessTableHost::tableFor($resourceClass);
    }

    /** @param class-string $resourceClass */
    public static function for(string $resourceClass): ?self
    {
        $table = self::table($resourceClass);
        $column = $table->getReorderColumn();

        if (blank($column) || str_contains($column, '.')) {
            return null;
        }

        return new self($column, $table->getReorderDirection());
    }

    /**
     * Filament's own gate, evaluated for THIS request —
     * `Table::isReorderable()` (`CanReorderRecords.php:104`), NOT merely
     * `isReorderAuthorized()`: `isReorderable()` is `filled(reorderColumn) &&
     * evaluate($isReorderable) && isReorderAuthorized()`, and that middle
     * term is `->reorderable('column', condition: …)`'s own `$condition` —
     * a second gate independent of `authorizeReorder()`, dropped entirely by
     * evaluating `isReorderAuthorized()` alone. Both closures commonly read
     * auth(), so the request is bound around the evaluation and restored
     * after, the same discipline HeadlessSchemaHost uses for state reads.
     *
     * @param class-string $resourceClass
     */
    public static function authorizes(string $resourceClass, Request $request): bool
    {
        $app = app();
        $previous = $app->bound('request') ? $app->make('request') : null;

        // `Illuminate\Auth\AuthServiceProvider` listens for `request` being
        // rebound in the container and overwrites `Request::setUserResolver()`
        // with an auth-guard-based resolver the instant `instance()` below
        // fires — clobbering whatever resolver the caller already set (e.g.
        // Pest's `requestAs()`). Re-applying it after the bind, once the
        // listener has already fired, is what makes `request()->user()`
        // inside the table's closure answer the request's OWN user again.
        $resolver = $request->getUserResolver();
        $app->instance('request', $request);
        $request->setUserResolver($resolver);

        try {
            return self::table($resourceClass)->isReorderable();
        } finally {
            $previous === null ? $app->forgetInstance('request') : $app->instance('request', $previous);
        }
    }
}
