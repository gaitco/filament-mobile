<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\FormDefaults;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Write\WritableNames;
use Gait\MobileCore\Authorizer;
use Gait\MobileCore\SettledSchema;
use Gait\MobileCore\WalkWarnings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Re-evaluates a resource's form against values the phone has typed but not yet
 * submitted, so `visible(fn (Get $get) => ...)` and friends answer on mobile
 * exactly as they do in the web panel.
 *
 * Contains no Livewire symbol, and must not: `Schema::make()` types its host
 * parameter as `Filament\Schemas\Contracts\HasSchemas`, so the host travels
 * through here as a contract. Exactly one file in `src/` names a Livewire
 * symbol — `Introspection/HeadlessSchemaHost.php` — and this is not it.
 * (ArchitectureTest exempts a second file, `Console/DoctorCommand.php`, but for
 * the neighbouring Filament tables ban; it names no Livewire symbol.)
 */
final class StateController
{
    public function __construct(private readonly ResourceRegistry $registry)
    {
    }

    public function __invoke(Request $request, string $resource): JsonResponse
    {
        // Same order as every other endpoint: resource resolution (404) before
        // the Gate (403), so a resource nobody serves never leaks its absence
        // as a permission error.
        [$class] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // `changed` is validated and deliberately unused: the response is the
        // whole re-walked form, not a diff, so nothing here needs to know which
        // field moved. It stays in the request contract because a client that
        // sends a malformed one should find out now rather than when a later
        // phase starts reading it.
        $validated = $request->validate([
            'record_id' => ['nullable'],
            // Absent is legal and means "no values yet"; present-but-not-array
            // is a malformed request, not an empty one.
            'state' => ['array'],
            'changed' => ['nullable', 'string'],
        ]);

        $recordId = $validated['record_id'] ?? null;

        // `nullable` alone still admits `record_id: []`, which would reach the
        // query builder as an array binding and fatal — a 500 for input the
        // client fully controls.
        abort_if($recordId !== null && ! is_scalar($recordId), 422, '[record_id] must be a scalar.');

        $record = $this->authorize($request, $class, $recordId);

        $state = $validated['state'] ?? [];

        // The record's stored values sit UNDER the submitted ones, exactly as
        // update() extracts its rules: a phone editing an existing record sends
        // only what it has touched, so a field whose visibility depends on an
        // untouched column must still resolve. Without this, the form the
        // client renders and the rule set the write accepts can disagree.
        // `attributesToArray()` for the same parity reason as update(): cast
        // values are what Filament's own EditRecord fills the form with.
        if ($record !== null) {
            $state = [...$record->attributesToArray(), ...$state];
        }

        // The record travels too, not only its attributes: /state publishes the
        // `disabled` and `hidden` flags a client renders from, and an
        // operation-scoped or record-typed gate answers as a *create* without
        // it. A form that draws itself editable and then has the write refuse
        // the field is worse than either answer alone.
        return response()->json($this->walk($class, $resource, $state, $record));
    }

    /**
     * The create/update split is the same one the write endpoints make, for the
     * same reason: with no record there is no per-record question to ask, and
     * with one, the class-level answer of an ownership policy is `true` for
     * every row. See Authorizer::allows() vs allowsRecord().
     *
     * Returns the record it authorized against, or null on the create path —
     * the caller needs it to seed the form with the values already stored.
     *
     * @param  class-string  $class
     */
    private function authorize(Request $request, string $class, mixed $recordId): ?Model
    {
        if ($recordId === null) {
            abort_unless(
                Authorizer::allows($request->user(), 'create', $class::getModel()),
                403,
            );

            return null;
        }

        $model = new ($class::getModel())();

        // Through the resource's query, so a record a global scope, a soft
        // delete or a tenant boundary hides is a genuine 404 — never a 403,
        // which would confirm the row exists.
        $record = $class::getEloquentQuery()
            ->where($model->qualifyColumn($model->getRouteKeyName()), $recordId)
            ->first()
            ?? abort(404, "No [{$class::getModel()}] record [{$recordId}].");

        abort_unless(
            Authorizer::allowsRecord($request->user(), 'update', $record),
            403,
        );

        return $record;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function walk(string $class, string $resourceKey, array $state, ?Model $record): array
    {
        $warnings = new WalkWarnings();

        $build = function (array $seed) use ($class, $warnings, $record): array {
            $components = $this->components($class, $seed, $warnings, $record);

            return [
                'components' => $components,
                'writable' => WritableNames::of($components),
            ];
        };

        // The same settle the write endpoints run, against the same trusted
        // values — that is the whole task. /state is a read, so its own answer
        // is not a security boundary; but a field it draws editable that
        // update() then refuses is a control whose input is silently discarded,
        // and the only way two endpoints cannot disagree is to build the same
        // way. See MobilePanelController::store()/update().
        $settled = SettledSchema::settle(
            submitted: $state,
            trusted: $record !== null
                ? $record->attributesToArray()
                // From an EMPTY state, exactly as store() does, and for the
                // same reason: `trusted` seeds every pass and is never
                // re-derived, so a default planted there by a crafted gate is
                // a permanent floor. See GateFlipTest's two-hop case.
                : FormDefaults::fromComponents(
                    $this->components($class, [], $warnings, null),
                ),
            build: $build,
        );

        $components = $settled->components();

        $document = [
            'components' => $this->lockRefused(
                (new SchemaWalker($warnings))->walk($components, class_basename($class), $resourceKey, $class::getModel()),
                // The final build's own answer is NOT what the write accepts:
                // the allow-set only ever shrinks, so a name it dropped on an
                // earlier pass stays dropped even when the reset makes the last
                // build report it writable again. update() intersects for
                // exactly this reason (MobilePanelController::allowedRules()),
                // and publishing the un-intersected answer would reopen the
                // divergence from the other side.
                array_diff(WritableNames::of($components), $settled->writable()),
            ),
        ];

        // Read at request time, not cached at boot, and identical to /schema's
        // rule: diagnostics are for developers, never for a phone in the wild.
        if (! app()->environment('production')) {
            $document['_warnings'] = $warnings->all();
        }

        return $document;
    }

    /**
     * `withHidden: true` is load-bearing twice over. It is what makes a hidden
     * field appear in the response at all — the contract's `hidden` flag is the
     * answer the client needs, and a dropped component is not an answer. It is
     * also what keeps a throwing closure a warning instead of a 500: without
     * it, `getComponents()` filters on `isHidden()` during *construction*,
     * outside SafeEvaluator's reach.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return list<object>
     */
    private function components(
        string $class,
        array $state,
        WalkWarnings $warnings,
        ?Model $record,
    ): array {
        $host = new HeadlessSchemaHost();
        // Nested arrays are seeded as-is: Filament addresses state by state
        // path through data_get(), which walks them. Flattening would break it.
        $host->setMobileState($state);

        try {
            // `->model()` for the same reason the write endpoints set it: a
            // `Select::relationship()` resolves no options without it, and
            // /state is where a reactive picker's options are re-read. The
            // record supplies it too — `Schema::record()` *is* `model()`.
            //
            // `->operation()` because `getOperation()` otherwise falls through
            // to `getLivewire()::class`, which matches nothing inside
            // `disabledOn()`/`hiddenOn()`/`visibleOn()`, so every one of them
            // answered false. Derived from the record so it cannot disagree
            // with the write endpoint that follows.
            return $class::form(
                Schema::make($host)
                    ->model($record ?? $class::getModel())
                    ->operation($record === null ? 'create' : 'edit'),
            )->getComponents(withHidden: true);
        } catch (Throwable $e) {
            // The same construction-time hazard PanelSchemaBuilder guards: a
            // closure typed against the record fatals when the schema is built
            // without one. An empty form is a recoverable answer for the client;
            // a 500 on every keystroke is not.
            $warnings->add(
                class_basename($class),
                'form()',
                'could not build the form schema: ' . $e->getMessage(),
            );

            return [];
        }
    }

    /**
     * Publish `disabled` for the fields the settle refused, whatever the final
     * build says about them.
     *
     * Narrow on purpose: `$refused` is the shrink-only residual — a name the
     * last build reports writable but the accumulated allow-set dropped — which
     * is empty on an ordinary request. It is NOT "everything absent from the
     * allow-set": a `dehydrated(fn ($state) => filled($state))` field is absent
     * whenever it is empty, and locking that would render a password field
     * read-only and unsettable forever. See FieldPersistence::neverPersists().
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<int, string>  $refused
     * @return list<array<string, mixed>>
     */
    private function lockRefused(array $nodes, array $refused): array
    {
        if ($refused === []) {
            return $nodes;
        }

        foreach ($nodes as $index => $node) {
            if (isset($node['children'])) {
                $nodes[$index]['children'] = $this->lockRefused($node['children'], $refused);

                continue;
            }

            if (in_array($node['name'] ?? null, $refused, true)) {
                $nodes[$index]['disabled'] = true;
            }
        }

        return $nodes;
    }
}
