<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Validation;

use Gait\FilamentMobile\Introspection\ChildComponents;
use Gait\FilamentMobile\Introspection\ComponentTypeMap;
use Gait\FilamentMobile\Introspection\FieldPersistence;
use Throwable;

/**
 * Turns a Filament schema tree into a Laravel rules array.
 *
 * Reads the same components the walker reads, so a field's server-side rules
 * and the `rules` hint the client renders can never disagree — there is one
 * source of truth and it is the resource's own form definition. That only
 * holds if this class recurses into exactly the same components the walker
 * does (see childrenOf()): a component ComponentTypeMap doesn't map is
 * unrenderable and, like the walker, contributes nothing here either.
 */
final class RuleExtractor
{
    /**
     * @param  iterable<mixed>  $components
     * @return array<string, list<string>>
     */
    public static function fromComponents(iterable $components): array
    {
        // Relation-write components are leaves but never rules: their value
        // reaches the database through saveRelationships(), and a rule here
        // would put the name into the validated payload — the mass-assignment
        // whitelist — where an update() writes it as a COLUMN that does not
        // exist. The CheckboxList fixture (`->relationship()->dehydrated()`)
        // is the reachable case: dehydration alone would admit it.
        return array_map(
            static fn (array $entry): array => self::rulesFor($entry['component']),
            array_filter(
                self::leavesOf($components),
                static fn (array $entry): bool => ! FieldPersistence::savesViaRelationship($entry['component']),
            ),
        );
    }

    /**
     * The relation-write leaves — the components the controller's relation
     * pass saves — off the SAME descent as the rules, so "has no rule" and
     * "is saved as a relation" cannot drift apart. Disabled ones (and ones
     * whose disabled gate throws) are already dropped by the descent's
     * fail-closed refusal.
     *
     * `&& $entry['writable']` matters since P6c Task 2: a relation-write
     * field nested INSIDE a repeater's item template (e.g. a
     * `CheckboxList::relationship()` in `Repeater::make('items')->schema([
     * ...])`) is a leaf whose own `savesViaRelationship()` answers `true`,
     * but its entry is minted by the repeater branch with a STARRED name
     * (`items.*.tags`) and `writable: false` — the same per-item entry the
     * rules deliberately publish. Without this clause that starred name
     * still passed the relation filter and reached `WritableNames::of()`,
     * which is the exact defect this task exists to prevent: `Arr::has`/
     * `Arr::set` treat `*` as an ordinary segment, so a payload shaped
     * `{"items": {"*": {"tags": [...]}}}` would match, settle, and reach a
     * real `saveRelationships()` call for a name no schema ever named at
     * top level.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, object>
     */
    public static function relationWriteComponents(iterable $components): array
    {
        return array_map(
            static fn (array $entry): object => $entry['component'],
            array_filter(
                self::leavesOf($components),
                static fn (array $entry): bool => $entry['writable']
                    && FieldPersistence::savesViaRelationship($entry['component']),
            ),
        );
    }

    /**
     * The writable leaves — one name per value the write path may trust as a
     * unit. Off the SAME descent as the rules, but NOT `array_keys(
     * fromComponents())`: a repeater's rules also carry its own per-item
     * paths (`items.*.name`, see childrenOf()), and those must never be
     * treated as writable — SettledSchema::reset() calls Arr::has()/
     * Arr::set(), neither of which understands a wildcard segment (see the
     * design spec's "two different name spaces").
     *
     * `writable` is recorded on each leaf where the descent MINTS it — true
     * for a repeater's own name and every ordinary leaf, false for a
     * per-item entry childrenOf() prefixes — so this is a second output of
     * the descent, a fact about how each entry was constructed, rather than
     * a filter that re-derives "is this a per-item path" by pattern-matching
     * the name string afterwards. See WritableNames::of(), which composes
     * this with relationWriteComponents().
     *
     * @param  iterable<mixed>  $components
     * @return array<string, object>
     */
    public static function writableComponents(iterable $components): array
    {
        return array_map(
            static fn (array $entry): object => $entry['component'],
            array_filter(
                self::leavesOf($components),
                static fn (array $entry): bool => $entry['writable']
                    && ! FieldPersistence::savesViaRelationship($entry['component']),
            ),
        );
    }

    /**
     * The `:attribute` each rule above must interpolate, keyed the same way.
     *
     * Filament's own rule closures pass `getValidationAttribute()` — the
     * field's label, not its column name — and `SchemaWalker::rules()`
     * publishes its `messages` through the same accessor. Laravel's validator
     * knows nothing about either and falls back to the humanised key, so
     * without this the published hint and the authoritative `422` describe one
     * rule with two different nouns. In a non-English panel that is worse than
     * cosmetic: the `422` puts a raw English column name inside an otherwise
     * translated sentence.
     *
     * Off the SAME descent as the rules, deliberately. A parallel walk is how
     * "the field's rules" and "the field's name" drift apart, and the drift is
     * silent — a name present here but absent there simply never applies.
     *
     * Passing an attribute for EVERY name, including the unlabelled ones, is
     * safe rather than lazy: Filament's `getDefaultLabel()` humanises the name
     * to exactly what Laravel's own `:attribute` fallback produces (both give
     * "bounded quantity" for `bounded_quantity`), so an unlabelled field reads
     * identically with or without this. Filtering to "only the ones that
     * differ" would need this class to reimplement Laravel's fallback and stay
     * in step with it. The empty check is for a component whose accessor is
     * absent or threw — there the fallback is the only answer left.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, string>
     */
    public static function attributesFrom(iterable $components): array
    {
        $attributes = [];

        foreach (self::leavesOf($components) as $name => $entry) {
            $attribute = self::read($entry['component'], 'getValidationAttribute');

            if (is_string($attribute) && $attribute !== '') {
                $attributes[$name] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * The one descent every public method reads, name => {component,
     * writable}. `writable` is minted here, at the point each entry is
     * constructed — true for an ordinary leaf and a repeater's own name,
     * false for a per-item entry the repeater branch prefixes below — so it
     * is a fact recorded about how the entry was found, not something a
     * caller re-derives from the name string afterwards.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, array{component: object, writable: bool}>
     */
    private static function leavesOf(iterable $components): array
    {
        $leaves = [];

        foreach ($components as $component) {
            // A schema legally mixes raw strings in with components (e.g.
            // ->schema(['some text', TextInput::make(...)])); the walker
            // filters those out for the same reason before touching them.
            if (! is_object($component)) {
                continue;
            }

            if (ComponentTypeMap::isSkipped($component)) {
                continue;
            }

            foreach (self::childrenOf($component) as $name => $leaf) {
                $leaves[$name] = $leaf;
            }
        }

        return $leaves;
    }

    /**
     * @return array<string, array{component: object, writable: bool}>
     */
    private static function childrenOf(object $component): array
    {
        $type = ComponentTypeMap::for($component);

        // Unmapped means the walker drops it with a warning and never emits
        // a node for it — a client can't render or submit it, so no rule
        // should apply to it either. Recursing into it anyway (the original
        // bug) let an unsupported container's descendants leak in as
        // top-level fields, free to collide with real ones.
        if ($type === null) {
            return [];
        }

        // Multiple-file stays withheld: this slice (P6a) has nowhere to save
        // more than one path per column, and a client that submitted the key
        // anyway — by accident or on purpose — would otherwise overwrite or
        // *clear* a stored value with whatever scalar it sent. Withholding
        // the rule is what drops it: the rules array is also the
        // mass-assignment whitelist (only a validated key reaches create() or
        // update()), so no rule means no key, in store() and update() alike.
        // A second filter applied per controller method could drift between
        // the two; this cannot.
        //
        // A SINGLE-file field now carries a rule like any other leaf: its
        // stored value is a PATH STRING, never bytes — bytes travel through
        // Upload\UploadFieldResolver and the upload endpoint, which hand back
        // a path the ordinary write path then saves exactly like any other
        // string column. `getName()` below still gates on isNotSaved(), so a
        // disabled or non-dehydrating single-file field is withheld the same
        // way any other field is.
        //
        // `!== false`, not `=== true`: the rule is admitted only when
        // isMultiple() ANSWERS single. A throw (read() returns null) is
        // withheld, because admitting on a throw fails OPEN — the field
        // enters WritableNames and PUT will write or clear its column while
        // UploadFieldResolver refuses its every upload. The walker's
        // isMultipleFile() and the resolver give the same closed answer on
        // the same throw, so all three agree.
        if ($type === 'file' && self::read($component, 'isMultiple') !== false) {
            return [];
        }

        if (in_array($type, ComponentTypeMap::LAYOUT_TYPES, true)) {
            // A container carries `disabled()` down to every field inside it —
            // gating a whole Section on a permission is the idiomatic way to
            // write that — so the check below must run before the recursion,
            // not only on leaves.
            if (self::isNotSaved($component)) {
                return [];
            }

            // The same descent the walker uses, and it has to be: a child read
            // out of the raw stored array cannot answer isDisabled() or
            // isDehydrated() at all — both throw, both fail open, and the two
            // guards below become dead code for every nested field. See
            // ChildComponents.
            return self::leavesOf(ChildComponents::of($component));
        }

        // A repeater is neither a leaf nor a pass-through container — it is
        // BOTH: one writable name for the whole array, and a per-item
        // template that needs its own rules so the server enforces what a
        // row must contain. Its own gate must still run BEFORE the
        // recursion, exactly like a layout container's above: `disabled()`
        // (and a relationship repeater's literal `dehydrated(false)`, see
        // FieldPersistence::savesViaRelationship()'s singular-container
        // branch) refuses the whole field, rows included, so a disabled or
        // relationship repeater contributes nothing at all — no rule, no
        // per-item rule, no writable name.
        if ($type === 'repeater') {
            // Three refusals, and all three are the WHOLE field: a repeater's
            // value is one array attribute, so anything that would stop part
            // of it round-tripping has to stop all of it.
            //
            //  - `isNotSaved()`: disabled, or a relationship repeater's
            //    literal `dehydrated(false)` — as for any other field.
            //  - `refusesRelationship()`: the same gate SchemaWalker publishes
            //    as `config.readOnly`. Read here too so the flag and the write
            //    path cannot disagree — `->relationship()->dehydrated(true)`
            //    made them disagree, and a crafted payload then reached
            //    `update()` as a column that does not exist.
            //  - `withheldChild()`: a child whose own rule would be withheld.
            //    At top level withholding a rule PROTECTS the column (no key,
            //    so `update()` never touches it). Inside a repeater the whole
            //    array is one attribute, so `validated()` rebuilds it from the
            //    paths the rules name and the unruled child's key is DELETED
            //    from every row that gets written — the same mechanism with
            //    the opposite outcome. There is no row identity on the wire to
            //    merge the stored value back by (no keys, no reorder; an
            //    index-merge pairs row 2's id with row 3's data the moment a
            //    row is added or removed), so the field fails closed rather
            //    than corrupting an identifier.
            if (self::isNotSaved($component)
                || FieldPersistence::refusesRelationship($component)
                || self::withheldChild(ChildComponents::of($component)) !== null) {
                return [];
            }

            $name = self::read($component, 'getName');

            if (! is_string($name) || $name === '') {
                return [];
            }

            $leaves = [$name => ['component' => $component, 'writable' => true]];

            // Prefixed `items.*.field`, never a bare `field`: a bare name
            // would hoist the item template's fields to top level, free to
            // collide with an unrelated field of the same name — the exact
            // bug LAYOUT_TYPES recursion exists to avoid for a real
            // container. `writable: false` is the other half of the split:
            // Arr::has()/Arr::set() (SettledSchema::reset()) have no
            // wildcard support, so this name must never enter WritableNames
            // — only the whole-array name above may.
            foreach (self::leavesOf(ChildComponents::of($component)) as $childName => $childEntry) {
                $leaves["{$name}.*.{$childName}"] = [
                    'component' => $childEntry['component'],
                    'writable' => false,
                ];
            }

            return $leaves;
        }

        if (self::isNotSaved($component)) {
            return [];
        }

        $name = self::read($component, 'getName');

        if (! is_string($name) || $name === '') {
            return [];
        }

        return [$name => ['component' => $component, 'writable' => true]];
    }

    /**
     * The first child of a repeater's item template whose value would NOT
     * round-trip — by name, or by class when it has no readable name — and
     * null when every child does.
     *
     * "Round-trips" means childrenOf() above would mint a leaf for it, so
     * `validated()` carries its key back out of every row. Every branch here
     * mirrors one of childrenOf()'s own refusals, in the same order, and the
     * two must be changed together: a refusal added there without one here
     * publishes an editable repeater that eats the child's stored value on
     * every save, which is exactly the defect this method exists to close.
     *
     * The descent goes THROUGH a nested container and a nested repeater
     * rather than stopping at it, because the outer array is written whole:
     * a `Hidden` two levels down is stripped from every inner row by the same
     * `validated()` rebuild, and the outer save is what writes it.
     *
     * Public because SchemaWalker asks the same question to decide
     * `config.readOnly` — one predicate, so the published flag and the write
     * path's refusal cannot drift.
     *
     * @param  iterable<mixed>  $components  a repeater's item template
     */
    public static function withheldChild(iterable $components): ?string
    {
        foreach ($components as $component) {
            // A raw string child carries no value, so it has nothing to lose.
            if (! is_object($component)) {
                continue;
            }

            // Hidden — dropped by ComponentTypeMap::SKIPPED, and the single
            // most common shape this refusal exists for: `Hidden::make('id')`
            // inside a repeater had its value destroyed on every save.
            if (ComponentTypeMap::isSkipped($component)) {
                return self::labelOf($component);
            }

            $type = ComponentTypeMap::for($component);

            // Unmapped: the walker drops it and no rule applies to it.
            if ($type === null) {
                return self::labelOf($component);
            }

            // A relation-write child (a `CheckboxList::relationship()` in a
            // row). This mirrors fromComponents()'s OWN extra filter rather
            // than childrenOf()'s refusals — the two disagree here, and that
            // divergence is what this clause exists to close: childrenOf()
            // MINTS the starred entry, so the descent alone says "fine",
            // while fromComponents() drops every relation-write leaf from the
            // rules. No rule inside a repeater means the key is deleted from
            // every stored row.
            //
            // Exempt only when dehydration ANSWERS false — the same `!== false`
            // shape the file branch below uses, for the same reason. A plain
            // `relationship()` sets `dehydrated(false)` as a literal, so
            // Filament never puts a key in the row's state and there is
            // nothing for the missing rule to strip; `->dehydrated(true)`
            // overrides that literal and the key IS stored, with no rule to
            // carry it back — measured as `[{"title":"A","tags":[1,2]}]`
            // saving as `[{"title":"A"}]` behind a 200. A gate that cannot
            // answer (read() returns null on a throw) refuses rather than
            // admits, the standing rule everywhere else in this package.
            //
            // A DISABLED relation-write child is refused by isNotSaved()
            // below, through its savesViaRelationship branch; only the
            // dehydration half is left to ask here.
            if (FieldPersistence::savesViaRelationship($component)
                && self::read($component, 'isDehydrated') !== false) {
                return self::labelOf($component);
            }

            // Multiple-file: rule withheld, as at top level.
            if ($type === 'file' && self::read($component, 'isMultiple') !== false) {
                return self::labelOf($component);
            }

            // Disabled, never dehydrated, or a gate that cannot answer. This
            // is the permission-boundary idiom inverted: `->disabled(fn () =>
            // ! $user->can('rates.manage'))` on a child protects nothing here,
            // it deletes the manager's value from every row.
            if (self::isNotSaved($component)) {
                return self::labelOf($component);
            }

            if ($type === 'repeater' && FieldPersistence::refusesRelationship($component)) {
                return self::labelOf($component);
            }

            if (in_array($type, ComponentTypeMap::LAYOUT_TYPES, true) || $type === 'repeater') {
                $withheld = self::withheldChild(ChildComponents::of($component));

                if ($withheld !== null) {
                    return $withheld;
                }

                continue;
            }

            $name = self::read($component, 'getName');

            if (! is_string($name) || $name === '') {
                return self::labelOf($component);
            }
        }

        return null;
    }

    private static function labelOf(object $component): string
    {
        $name = self::read($component, 'getName');

        return is_string($name) && $name !== '' ? $name : $component::class;
    }

    /**
     * Withholding the rule is what drops the field — the rules array is also
     * the mass-assignment whitelist, so no rule means no key, in store() and
     * update() alike. Same mechanism as a `file` field.
     *
     * This is a permission boundary, not a nicety. `->disabled(fn () =>
     * ! auth()->user()->can('rates.manage'))` is how a panel makes a field
     * read-only for users who may edit the record but not that column, and
     * `->dehydrated(fn ($state) => filled($state) && $user->can(...))` is how
     * it stops a crafted save from overwriting a stored secret. The write
     * pilot measured 12 such fields across 8 resources for an ordinary
     * low-privilege account — including `customs_duty_rate`, `vat_rate`,
     * `exchange_rate` and an encrypted credential.
     *
     * A `Select::relationship()` is `dehydrated(false)` too: Filament writes
     * the pivot in a separate pass this package does not run. Before this,
     * `roles` was accepted, reported 201, and silently attached nothing.
     *
     * See FieldPersistence for why both accessors are consulted and why a gate
     * that throws refuses rather than admits.
     */
    private static function isNotSaved(object $component): bool
    {
        // A relation-write component's dehydration is false BY DESIGN —
        // Filament saves it through saveRelationships(), not the payload —
        // so only its disabled gate may refuse it, fail closed as ever.
        if (FieldPersistence::savesViaRelationship($component)) {
            return FieldPersistence::refusesDisabled($component);
        }

        return FieldPersistence::refuses($component);
    }

    /**
     * A field with no constraints still gets `nullable` rather than being
     * omitted: an absent key would pass through the validator unchecked.
     *
     * @return list<string>
     */
    private static function rulesFor(object $component): array
    {
        // A repeater's own rule is shaped differently from an ordinary
        // field's — it bounds an ARRAY, not a string or number — so
        // `min`/`max` here mean row count, derived from minItems()/
        // maxItems() rather than getMinLength()/getMaxLength(). Publishing
        // them is what makes the server enforce the same bound the config
        // already publishes to the client (design spec).
        if (ComponentTypeMap::for($component) === 'repeater') {
            // `list` as well as `array`, because PHP's `array` admits a
            // string-keyed map and the contract's repeater value is a LIST of
            // maps. Without it a crafted `{"*": {...}}` validated cleanly —
            // the wildcard rules match a literal `*` key perfectly happily —
            // stored verbatim behind a 200, and the client then rendered zero
            // rows and overwrote the column on the first Add. An empty list
            // still passes (`[]` is a list); `min`/`max` below count rows
            // either way.
            $rules = ['array', 'list'];

            if (self::read($component, 'isRequired') === true) {
                $rules[] = 'required';
            }

            $min = self::read($component, 'getMinItems');

            if (is_int($min)) {
                $rules[] = "min:{$min}";
            }

            $max = self::read($component, 'getMaxItems');

            if (is_int($max)) {
                $rules[] = "max:{$max}";
            }

            return $rules;
        }

        $rules = [];

        if (self::read($component, 'isRequired') === true) {
            $rules[] = 'required';
        }

        if (self::read($component, 'isEmail') === true) {
            $rules[] = 'email';
        }

        if (self::read($component, 'isNumeric') === true) {
            $rules[] = 'numeric';
        }

        $max = self::read($component, 'getMaxLength');

        if (is_int($max)) {
            $rules[] = "max:{$max}";
        }

        $min = self::read($component, 'getMinLength');

        if (is_int($min)) {
            $rules[] = "min:{$min}";
        }

        return $rules === [] ? ['nullable'] : $rules;
    }

    /**
     * Accessors on an unattached component throw — the same hazard the walker
     * handles. A property that cannot be read simply does not contribute a rule.
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
