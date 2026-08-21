<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\ChildComponents;
use Gait\FilamentMobile\Introspection\ComponentTypeMap;
use Gait\FilamentMobile\Introspection\FormDefaults;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
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
 * Serves the options for a select whose list `/schema` refused to inline.
 *
 * A node publishes `config.optionsUrl` when its options are not knowable at
 * publish time — a searchable relationship select, which Filament deliberately
 * declines to enumerate — or when the list outgrew the wire. This is where the
 * client fetches them instead.
 *
 * Contains no Livewire symbol, and must not: exactly one file in `src/` names
 * one, `Introspection/HeadlessSchemaHost.php`, and this is not it.
 */
final class OptionsController
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

        $validated = $request->validate([
            'field' => ['required', 'string'],
            'record_id' => ['nullable'],
            // Absent is legal and means "no values yet"; present-but-not-array
            // is a malformed request, not an empty one.
            'state' => ['array'],
            'q' => ['nullable', 'string'],
        ]);

        $recordId = $validated['record_id'] ?? null;

        // `nullable` alone still admits `record_id: []`, which would reach the
        // query builder as an array binding and fatal — a 500 for input the
        // client fully controls.
        abort_if($recordId !== null && ! is_scalar($recordId), 422, '[record_id] must be a scalar.');

        // Before the field is resolved, deliberately: a forbidden user must not
        // learn which fields a resource has by watching 422 turn into 200.
        $record = $this->authorize($request, $class, $recordId);

        $state = $validated['state'] ?? [];

        if ($record !== null) {
            $state = [...$record->attributesToArray(), ...$state];
        }

        $component = $this->resolve($class, $state, $record, $validated['field']);

        // 422 rather than 404 for a field that is not here or is not a select.
        // A 404 would answer a question the client did not earn: whether the
        // resource has such a column at all.
        abort_if($component === null, 422, "[{$validated['field']}] is not a searchable field.");

        $results = $this->search($component, (string) ($validated['q'] ?? ''));

        return response()->json([
            'options' => $results,
            // Filament caps its own search at `optionsLimit` (50 by default),
            // so a full page is the only evidence there might be more — the
            // query never reports a total. Saying "there may be more" when the
            // page is exactly full is the honest answer; the alternative is
            // implying a truncated list is complete, which is how a user
            // concludes their record does not exist.
            'hasMore' => count($results) >= $this->limitOf($component),
        ]);
    }

    /**
     * The create/update split every other endpoint makes, for the same reason:
     * with no record there is no per-record question to ask, and with one, the
     * class-level answer of an ownership policy is `true` for every row.
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
     * The named select, resolved out of a schema built from SETTLED state.
     *
     * This is the security property of the endpoint. Options depend on
     * siblings — the write pilot measured `user_id` narrowing `company_id`'s
     * options 6 -> 2 -> 1 — so resolving against the raw payload lets a crafted
     * sibling return a different tenant's list. Options are a read, but "which
     * companies belong to user 7" is exactly the read a scoped panel exists to
     * prevent. Settling first means a name the schema will not write cannot
     * steer which options come back.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     */
    private function resolve(string $class, array $state, ?Model $record, string $field): ?object
    {
        $warnings = new WalkWarnings();

        $build = function (array $seed) use ($class, $warnings, $record): array {
            $components = $this->components($class, $seed, $warnings, $record);

            return [
                'components' => $components,
                'writable' => WritableNames::of($components),
            ];
        };

        $settled = SettledSchema::settle(
            submitted: $state,
            trusted: $record !== null
                ? $record->attributesToArray()
                // From an EMPTY state, exactly as store() and /state do, and
                // for the same reason: `trusted` seeds every pass and is never
                // re-derived, so a default planted there by a crafted gate
                // would be a permanent floor.
                : FormDefaults::fromComponents($this->components($class, [], $warnings, null)),
            build: $build,
        );

        return $this->findSelect($settled->components(), $field);
    }

    /**
     * Depth-first through the same container descent the walker and the rule
     * extractor use, so a select nested in a Section is found exactly where
     * they would find it.
     *
     * The descent also goes THROUGH a repeater into its item template: the
     * client renders a row's select off the template and asks for it by its
     * bare child name, so a lookup that stopped at the repeater's border
     * would 422 a node the schema itself published with an `optionsUrl`.
     *
     * @param  iterable<mixed>  $components
     */
    private function findSelect(iterable $components, string $field): ?object
    {
        foreach ($components as $component) {
            if (! is_object($component) || ComponentTypeMap::isSkipped($component)) {
                continue;
            }

            $type = ComponentTypeMap::for($component);

            if ($type !== null
                && (in_array($type, ComponentTypeMap::LAYOUT_TYPES, true) || $type === 'repeater')) {
                $found = $this->findSelect(ChildComponents::of($component), $field);

                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            // Both `select` and `multiselect`: Filament's isSearchable()
            // defaults to isMultiple(), so a plain ->multiple() relationship
            // select reaches the client as an optionsUrl node without ever
            // calling ->searchable(). Serving only the explicit ones would 422
            // on a node the schema itself told the client to fetch.
            if (! in_array($type, ['select', 'multiselect'], true)) {
                continue;
            }

            if (! method_exists($component, 'getName') || ! method_exists($component, 'getSearchResults')) {
                continue;
            }

            try {
                if ($component->getName() === $field) {
                    return $component;
                }
            } catch (Throwable) {
                // A name that cannot be read is a name nothing can ask for.
                continue;
            }
        }

        return null;
    }

    /**
     * Filament's own ceiling for this select, which is what actually truncates
     * the result — not a limit of ours. Falls back to its documented default
     * when the accessor is absent or throws.
     */
    private function limitOf(object $component): int
    {
        if (! method_exists($component, 'getOptionsLimit')) {
            return 50;
        }

        try {
            return max(1, (int) $component->getOptionsLimit());
        } catch (Throwable) {
            return 50;
        }
    }

    /**
     * @return list<array{value: mixed, label: string}>
     */
    private function search(object $component, string $query): array
    {
        try {
            /** @var array<mixed, mixed> $results */
            $results = $component->getSearchResults($query);
        } catch (Throwable) {
            // A search closure reaching for context that does not exist outside
            // a Livewire request costs this one picker, not the request. The
            // client shows an empty list and the user keeps typing.
            return [];
        }

        $flat = [];

        foreach ($results as $value => $label) {
            // Filament allows a grouped shape here for the same reason
            // `options()` does; a group's own label is not selectable.
            if (is_array($label)) {
                foreach ($label as $nested => $nestedLabel) {
                    $flat[] = ['value' => $nested, 'label' => (string) $nestedLabel];
                }

                continue;
            }

            $flat[] = ['value' => $value, 'label' => (string) $label];
        }

        return $flat;
    }

    /**
     * `withHidden: true` for the same two reasons /state passes it: a hidden
     * component must still be reachable, and without it `getComponents()`
     * filters on `isHidden()` during *construction*, outside SafeEvaluator's
     * reach, so a throwing closure becomes a 500 rather than a warning.
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
        $host->setMobileState($state);

        try {
            return $class::form(
                Schema::make($host)
                    ->model($record ?? $class::getModel())
                    ->operation($record === null ? 'create' : 'edit'),
            )->getComponents(withHidden: true);
        } catch (Throwable $e) {
            $warnings->add(class_basename($class), 'form', $e->getMessage());

            return [];
        }
    }
}
