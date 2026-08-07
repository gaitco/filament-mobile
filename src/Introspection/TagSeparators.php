<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Schemas\Schema;
use Throwable;

/**
 * The one deliberate exception: the only place this package reproduces
 * Filament's own dehydration.
 *
 * `TagsInput::make('labels')->separator(',')` changes what gets PERSISTED, not
 * merely what gets rendered. Measured in `TagsInput::setUp()`:
 *
 *     $this->dehydrateStateUsing(static function (TagsInput $component, $state) {
 *         if ($separator = $component->getSeparator()) {
 *             return implode($separator, $state);   // the column stores "a,b,c"
 *         }
 *
 *         return $state;                            // otherwise an array
 *     });
 *
 * and its inverse in `hydrateTags()`, which explodes the column back into an
 * array when the form is filled. This package's write path deliberately never
 * runs Filament's dehydration — it writes `validated()` straight to the model —
 * so without this class a client sending an array to a separator-configured
 * field stores an array where the web panel stores a delimited string: two
 * surfaces, two shapes, one column.
 *
 * It is narrow ON PURPOSE and is not a new general capability. This package
 * does not host a Livewire form and does not intend to grow a dehydration
 * pipeline; `separator` earns the exception because it is the one mapped
 * config option that changes a column's SHAPE rather than its presentation. A
 * future Filament change to `dehydrateStateUsing` would silently diverge from
 * this reproduction — the tests assert the stored column, which is what would
 * catch it.
 *
 * The wire shape is unaffected in both directions: the client sends and
 * receives a `List<String>` whether a separator is configured or not, and never
 * sees or constructs the delimited form. The two halves below are the whole of
 * that guarantee.
 */
final class TagSeparators
{
    /**
     * The write half — Filament's `dehydrateStateUsing`, applied to the
     * attributes about to be written.
     *
     * Applied to the FINAL attribute array, after defaults have been filled:
     * `TagsInput` sets a `[]` default state, so a create that never mentions
     * the field still carries an array into the insert, and a mirror that ran
     * only over `validated()` would leave that one array-shaped. `implode` of
     * `[]` is `""`, which is exactly what Filament's own dehydration writes.
     *
     * @param  array<string, mixed>  $attributes
     * @param  iterable<mixed>  $components
     * @return array<string, mixed>
     */
    public static function dehydrate(array $attributes, iterable $components): array
    {
        foreach (self::in($components) as $name => $separator) {
            // Only an array is joined. A non-array VALUE here is either
            // absent (nothing to write) or already refused by the `array`
            // rule every tags field carries — never silently coerced.
            //
            // Its ELEMENTS are a separate question, and the original version
            // of this comment answered the wrong one: `array`/`list`
            // constrain the container only, so before the P7 final review's
            // Finding 1 a nested element reached the `implode()` below and
            // raised "Array to string conversion" — a first-party 500 on a
            // well-formed payload. What guarantees a string here is the
            // `{$name}.*` => `['string', ...]` rule RuleExtractor now seeds
            // for EVERY tags field, not just the ones declaring nested
            // rules. If that seed is ever narrowed, this line crashes again.
            if (is_array($attributes[$name] ?? null)) {
                $attributes[$name] = implode($separator, $attributes[$name]);
            }
        }

        return $attributes;
    }

    /**
     * The read half — Filament's `hydrateTags()`, applied to a serialized
     * record before it goes out.
     *
     * Without it the delimited column would reach the client verbatim and a
     * separator-configured field would be the one place the wire value is a
     * string, breaking the `List<String>` contract every other tags field
     * keeps. The client is deliberately not taught to split: one shape on the
     * wire is the whole point of joining server-side.
     *
     * Takes the resolved MAP rather than components, because its caller is
     * RecordSerializer — which serves four seams (`index()`, `show()`, and the
     * `store()`/`update()` response bodies) off one resource and must resolve
     * the map once, not once per record. See forResource().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $separators  `name => separator`
     * @return array<string, mixed>
     */
    public static function hydrate(array $payload, array $separators): array
    {
        foreach ($separators as $name => $separator) {
            if (! array_key_exists($name, $payload) || is_array($payload[$name])) {
                continue;
            }

            $value = $payload[$name];

            // `hydrateTags()`'s own collapse, `blank()` included rather than a
            // bare `=== ''`: `explode()` on an empty string yields `['']`, and
            // a column holding whitespace is `blank()` to Filament, so its own
            // form shows `[]` where a stricter test would publish `["   "]`.
            // The point of a mirror is that the two agree on the edges too.
            $tags = explode($separator, is_string($value) ? $value : '');

            $payload[$name] = (count($tags) === 1 && blank($tags[0])) ? [] : $tags;
        }

        return $payload;
    }

    /**
     * The map for a resource's own form — the read path's entry point, since
     * `index()` has no schema in hand and building one per record would be
     * absurd.
     *
     * Guarded whole: a form that cannot be built headlessly (a closure wanting
     * a real Livewire host, a `Get` on state that is not there) costs the
     * mirror for that resource, never the response. That degradation publishes
     * the raw column — the pre-P7 behaviour — rather than an error.
     *
     * `operation('edit')`, because every caller is serialising a record that
     * exists: a field the form reveals only on edit must be present for its
     * separator to be read, and `getComponents()` filters hidden ones.
     *
     * ponytail: one form build per RecordSerializer instance (it memoises), so
     * `index()` pays one extra schema build per request and `show()` a fourth.
     * If that ever shows up in a profile, the fix is to hand the map in from
     * the schema the write path already builds — not to cache it, which would
     * go stale against a state-dependent separator closure.
     *
     * @param  class-string  $class
     * @return array<string, string>
     */
    public static function forResource(string $class): array
    {
        try {
            $host = new HeadlessSchemaHost();
            $host->setMobileState([]);

            return self::in(
                $class::form(
                    Schema::make($host)->model($class::getModel())->operation('edit'),
                )->getComponents(),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The separator-configured tags fields, `name => separator`.
     *
     * Descends into layout containers only — never into a `repeater`, whose
     * children are per-item paths inside one JSON column rather than
     * attributes of their own (see ComponentTypeMap::LAYOUT_TYPES and the
     * design spec's "two different name spaces"). A separator-configured tags
     * field inside a repeater is therefore not mirrored; it is out of this
     * slice's scope and the repeater's column keeps the array it always held.
     *
     * Not filtered by FieldPersistence: a disabled or refused field is absent
     * from the write payload anyway, so filtering would change nothing there —
     * while on the READ side it is still published to the client and still
     * owes the `List<String>` shape.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, string>
     */
    private static function in(iterable $components): array
    {
        $separators = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $type = ComponentTypeMap::for($component);

            if ($type !== null && in_array($type, ComponentTypeMap::LAYOUT_TYPES, true)) {
                $separators = [...$separators, ...self::in(ChildComponents::of($component))];

                continue;
            }

            if ($type !== 'tags') {
                continue;
            }

            $name = self::read($component, 'getName');
            $separator = self::read($component, 'getSeparator');

            if (is_string($name) && $name !== '' && is_string($separator) && $separator !== '') {
                $separators[$name] = $separator;
            }
        }

        return $separators;
    }

    /**
     * Guarded, exactly as SchemaWalker reads the same getter: `separator()`
     * takes a closure, and one that cannot answer degrades this field to "no
     * separator" — which is what the walker publishes to the client, so both
     * halves of the contract fail the same way rather than disagreeing about
     * one column's shape.
     */
    private static function read(object $component, string $method): mixed
    {
        if (! method_exists($component, $method)) {
            return null;
        }

        try {
            return $component->{$method}();
        } catch (Throwable) {
            return null;
        }
    }
}
