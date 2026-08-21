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
     * @return array<string, list<mixed>>
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
            static fn (array $entry): array => self::rulesFor($entry),
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
     * Three shapes arrive here: a multi-valued relationship field (a
     * `Select::multiple()->relationship()`, a `CheckboxList::relationship()`);
     * since P9, a relationship REPEATER — minted by the descent's repeater
     * branch as a whole-array leaf with no per-item rules, saved by
     * Filament's own `Repeater::saveToRelationship()` when the pass calls
     * `saveRelationships()`; and, since P14, a Spatie media upload — which
     * does NOT go through `saveRelationships()` at all (there is no Eloquent
     * relation to save), but reaches `RecordForm::saveRelations()`'s own
     * `MediaReconciler` branch instead. All three share the one property this
     * method exists to answer: none may ever gain a rule, or its value would
     * enter the validated payload and `update()` would write it as a column
     * that does not exist.
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
     * @return array<string, array{component: object, writable: bool, rules?: list<mixed>}>
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
     * @return array<string, array{component: object, writable: bool, rules?: list<mixed>}>
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

        // A file field — single OR multiple — carries a rule like any other
        // leaf: its stored value is a PATH STRING (single) or a List<String>
        // of path strings (multiple, since P12 — saved wholesale like a
        // repeater's rows), never bytes. Bytes travel through
        // Upload\UploadFieldResolver and the upload endpoint, which hand
        // back a path the ordinary write path then saves. `getName()` below
        // still gates on isNotSaved(), so a disabled or non-dehydrating file
        // field is withheld the same way any other field is.
        //
        // `is_bool`, not `!== false`: the rule is admitted when isMultiple()
        // ANSWERS, either way. A throw (read() returns null) is still
        // withheld, because admitting on a throw fails OPEN — the field
        // enters WritableNames and PUT will write or clear its column while
        // UploadFieldResolver refuses its every upload. The walker's
        // fileMultiplicity() and the resolver give the same closed answer
        // on the same throw, so all three still agree.
        if ($type === 'file' && ! is_bool(self::read($component, 'isMultiple'))) {
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
        // refuses the whole field, rows included, so a disabled repeater
        // contributes nothing at all — no rule, no per-item rule, no
        // writable name.
        if ($type === 'repeater') {
            // isNotSaved() first, for both kinds below: for a JSON-column
            // repeater it is the ordinary disabled/undehydrated refusal, and
            // for a relationship repeater it is the disabled half alone (its
            // dehydration is false BY DESIGN — see isNotSaved()).
            if (self::isNotSaved($component)) {
                return [];
            }

            // A RELATIONSHIP repeater (P9) is a relation-write leaf, not a
            // column: its rows are child records the controller's relation
            // pass writes through Filament's own Repeater::saveToRelationship(),
            // the same machinery a multi-valued relationship select already
            // uses. It mints ONLY its whole-array name — no rule (fromComponents()
            // drops relation-write leaves, so the value never enters the
            // validated payload, where update() would write it as a column
            // that does not exist) and no per-item rules (they would pull
            // `tag_rows` into validated() through the back door, same 500).
            // The row-level enforcement a JSON repeater gets from per-item
            // rules is therefore absent here — Filament's own save is what
            // runs, exactly as on the web panel.
            //
            // A relationship gate that cannot ANSWER (a throwing
            // relationship() closure) still refuses the whole field, fail
            // closed: savesViaRelationship() catches the throw and answers
            // true, so without this check the relation pass would call
            // saveRelationships() on a component whose relationship throws —
            // a 500 on crafted input, the exact shape the pre-P9 refusal
            // existed to prevent.
            if (FieldPersistence::savesViaRelationship($component)) {
                if (FieldPersistence::refusesRelationship($component, $error) && $error !== null) {
                    return [];
                }

                $name = self::read($component, 'getName');

                if (! is_string($name) || $name === '') {
                    return [];
                }

                return [$name => ['component' => $component, 'writable' => true]];
            }

            // A JSON-column repeater. The one remaining refusal is still the
            // WHOLE field: its value is one array attribute, so anything that
            // would stop part of it round-tripping has to stop all of it.
            //
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
            //
            // refusesRelationship() is no longer checked here: it answered
            // true for every relationship repeater, which the branch above now
            // admits, and for a JSON-column repeater — the only kind that
            // reaches this point — its remaining true cases (no
            // getRelationship() method, or a throwing gate) are unreachable,
            // since a Repeater always has the method and a throwing gate made
            // savesViaRelationship() answer true above.
            if (self::withheldChild(ChildComponents::of($component)) !== null) {
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
                $prefixed = [
                    'component' => $childEntry['component'],
                    'writable' => false,
                ];

                // A child's already-resolved rules (the seeded per-element
                // `string` on a tags or multiple-file `{$name}.*` entry)
                // travel WITH the prefix — re-deriving them from the
                // component in rulesFor() would hand the per-element name
                // the field's own CONTAINER rules.
                if (isset($childEntry['rules'])) {
                    $prefixed['rules'] = $childEntry['rules'];
                }

                $leaves["{$name}.*.{$childName}"] = $prefixed;
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

        $nested = self::nestedRulesFor($component);

        // A gate that cannot answer refuses the WHOLE field — the standing
        // rule everywhere else in this package, and the one place on this
        // path where the ordinary catch-and-degrade read would be wrong.
        // Every other guarded read here degrades to a missing HINT; this one
        // degrades to a dropped CONSTRAINT, so failing open makes mobile
        // looser than web, which is the exact violation the starred name
        // exists to close. Same closed answer `FileUpload::make(
        // 'exploding_multiple')` already gets: no rule, so no key, so the
        // column can be neither written nor cleared. See nestedRulesFor().
        if ($nested === null) {
            return [];
        }

        $leaves = [$name => ['component' => $component, 'writable' => true]];

        // A tags field's ELEMENTS are strings, always — the contract says a
        // `tags` value is a `List<String>` in every case. The field's own
        // rules (`['array', 'list']`, in rulesFor()) constrain only the
        // CONTAINER, so without this seed nothing constrained element TYPE
        // at all, and the P7 final review measured both consequences:
        // `{"separated_labels": [["x"], "y"]}` reached
        // `TagSeparators::dehydrate()`'s `implode()` and answered 500
        // ("Array to string conversion") at both write seams, while
        // `{"labels": [{"x":"y"}]}` answered 200 and persisted a list of
        // maps — the one case where the published `List<String>` contract
        // was false. `max:20` on an array means COUNT ≤ 20, so even a
        // declared nested rule let the map through.
        //
        // A MULTIPLE file field (P12) gets the same seed for the same
        // reason: its wire value is a `List<String>` of stored paths, and
        // the container rules alone would let a crafted `[1, 2]` persist as
        // a list of ints behind a 200. Per-FILE constraints (mimetypes,
        // size KB) deliberately do NOT travel as `name.*` rules — Filament's
        // own flow applies those to uploaded files, and this contract
        // enforces them at upload time instead; what remains here is the
        // element TYPE.
        //
        // Seeded, not appended: a panel's own nested rules follow it, so
        // `labels.*` is `['string', 'max:20']` and both apply. `in_array`
        // rather than a dedupe over the whole list, because a nested rule
        // may be a Rule OBJECT and `array_unique()`'s default comparison
        // stringifies — a strict membership check is the only one that is
        // safe for every element a panel may declare.
        $seedsStringElement = $type === 'tags'
            || ($type === 'file' && self::read($component, 'isMultiple') === true);

        $nested = $seedsStringElement && ! in_array('string', $nested, true)
            ? ['string', ...$nested]
            : $nested;

        // `labels.*`, with NO child segment — and that is the whole
        // difference from the repeater branch above. A repeater emits
        // `items.*.childName` because it has child components; a
        // `TagsInput` has none, so the per-tag rules are the component's
        // OWN, reached through `Contracts\HasNestedRecursiveValidationRules`
        // (`getNestedRecursiveValidationRules()`, read from vendor, not
        // guessed). Nothing in this package handled that interface before
        // P7 Task 2, so a `->nestedRecursiveRules(['max:20'])` was enforced
        // by the web panel and silently unenforced here — the exact "mobile
        // must never be looser than web" violation Authorizer states.
        //
        // `writable: false`, because Arr::has()/Arr::set()
        // (SettledSchema::reset()) have no wildcard support, so this is a
        // name the settle's allow-set cannot express at all — its only job
        // is naming paths `Arr::has()` can match, and this is not one.
        //
        // MEASURED, because the repeater's rationale does not transfer and
        // an overstated one is how the next starred shape gets mis-scoped: a
        // starred name here would be INERT, not destructive. `Arr::has()`
        // never matches it, and `labels` — always minted alongside and
        // writable in its own right — carries the whole array through
        // regardless. P6c's "silently drops every submitted row" was correct
        // for `items.*.child`, whose `child` is NOT separately a top-level
        // writable name, so nothing else covers that write. Here something
        // does. Pinned by TagsTest's `Arr::has` assertion, which reds if a
        // future Laravel makes wildcards matchable.
        //
        // Minted only when there is something to enforce: an empty rule list
        // on a starred name is a name the settle must keep excluding for no
        // gain. Every tags field now has something (the `string` seed
        // above); a non-tags field that declares no nested rules still has
        // nothing.
        //
        // The rules travel ON the entry rather than being re-read from the
        // component by rulesFor(): the seed is a fact about how this entry
        // was minted, exactly like `writable`, and a second call to
        // `getNestedRecursiveValidationRules()` cannot know about it.
        if ($nested !== []) {
            $leaves["{$name}.*"] = [
                'component' => $component,
                'writable' => false,
                'rules' => $nested,
            ];
        }

        return $leaves;
    }

    /**
     * The per-element rules a component declares through
     * `Contracts\HasNestedRecursiveValidationRules` — today only
     * `TagsInput`.
     *
     * Three answers, and the third is why this does not go through read():
     *
     *  - `[]` — the component does not implement the interface at all (every
     *    other field type), or implements it and declares no rules. An
     *    ordinary writable field with no starred name.
     *  - a rule list — the per-element rules.
     *  - `null` — the accessor THREW, or answered something that is not a
     *    list. `nestedRecursiveRules()` takes a `bool|Closure $condition`
     *    that `getNestedRecursiveValidationRules()` evaluates, so this is
     *    reachable from a panel's own closure, not a theoretical case.
     *
     * read() collapses the first and third into `[]`, which fails OPEN here:
     * the field would be published freely editable with its per-tag bound
     * silently gone. The caller refuses the whole field on `null` instead —
     * a gate that cannot answer refuses, as everywhere else in this package.
     *
     * @return array<mixed>|null
     */
    private static function nestedRulesFor(object $component): ?array
    {
        if (! method_exists($component, 'getNestedRecursiveValidationRules')) {
            return [];
        }

        try {
            $rules = $component->getNestedRecursiveValidationRules();
        } catch (Throwable) {
            return null;
        }

        return is_array($rules) ? array_values($rules) : null;
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

            // A file field whose multiplicity cannot be READ (a throwing
            // isMultiple()) keeps its rule withheld, as at top level — the
            // one file shape P12 did not admit. A multiple file child whose
            // gate ANSWERS round-trips like any other admitted child.
            if ($type === 'file' && ! is_bool(self::read($component, 'isMultiple'))) {
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
     * Takes the whole descent entry, not just its component, because one
     * component can mint two names: a `TagsInput` is both `labels` (the
     * array) and `labels.*` (each tag), and the two want different rules
     * off the same object. Reading only `$entry['component']` here is how
     * the starred name would silently get the field's OWN rules — a
     * `required` on the field arriving as a `required` on every tag.
     *
     * A starred entry carries its rules already resolved, so this returns
     * them verbatim rather than re-reading the component: the descent
     * composes a tags field's `string` seed with the panel's declared
     * nested rules, and a second `getNestedRecursiveValidationRules()` call
     * here would answer the declared half only — silently dropping the seed
     * and reopening the 500 it exists to close.
     *
     * @param  array{component: object, writable: bool, rules?: list<mixed>}  $entry
     * @return list<mixed>
     */
    private static function rulesFor(array $entry): array
    {
        $component = $entry['component'];

        if (isset($entry['rules'])) {
            return $entry['rules'];
        }

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

        // A tags field's value is a LIST of strings on the wire in every
        // case, separator or not. Without this a client could send the
        // panel's own persisted delimited form (`"a,b,c"`) and store a string
        // where the read path hands back an array — and slip past every
        // `labels.*` rule on the way, since a string has no elements for the
        // wildcard to reach. Same shape as the repeater's above, and for the
        // same reason: `list` as well as `array`, because PHP's `array`
        // admits a string-keyed map and a crafted `{"*": "x"}` matches the
        // wildcard rules perfectly happily.
        if (ComponentTypeMap::for($component) === 'tags') {
            $rules = ['array', 'list'];
        }

        // `array` only, NOT `list`: unlike a `tags` field, a KeyValue's wire
        // shape is a MAP (`Map<String, String>`), so a string-keyed payload
        // is exactly the expected shape rather than the crafted attack
        // `list` guards against above. Its keys and values are strings by
        // construction (the design spec), so no further per-key rule is
        // needed.
        if (ComponentTypeMap::for($component) === 'keyvalue') {
            $rules = ['array'];
        }

        // P12: a MULTIPLE file field bounds an ARRAY of path strings, so it
        // is shaped like the repeater above rather than like an ordinary
        // field — `array` + `list` on the container (a crafted scalar or
        // string-keyed map is a contract violation, never a coercion), and
        // `min`/`max` derived from minFiles()/maxFiles(), where they mean
        // FILE COUNT — Filament's own getValidationRules() shapes a
        // multiple field identically (verified in vendor). The per-element
        // `string` arrives through childrenOf()'s `{$name}.*` machinery;
        // per-file mimetypes/size are enforced at upload time instead.
        //
        // Early return, like the repeater: the generic tail reads accessors
        // (getMaxLength() and friends) a file component does not have, and
        // count bounds that cannot be read degrade to no rule — the same
        // degrade-on-throw shape the repeater's min/max items already take.
        if (ComponentTypeMap::for($component) === 'file'
            && self::read($component, 'isMultiple') === true) {
            $rules = ['array', 'list'];

            if (self::read($component, 'isRequired') === true) {
                $rules[] = 'required';
            }

            $max = self::read($component, 'getMaxFiles');

            if (is_int($max)) {
                $rules[] = "max:{$max}";
            }

            $min = self::read($component, 'getMinFiles');

            if (is_int($min)) {
                $rules[] = "min:{$min}";
            }

            return $rules;
        }

        // P10: a slider's bounds are force-registered on the COMPONENT
        // (Slider::setUp(), measured in vendor), not declared through the
        // accessors this method reads — `numeric`/`min:`/`max:` live behind
        // rule() closures keyed off the raw state, so nothing above re-derives
        // them and an out-of-range write would sail through where the web
        // panel 422s. Re-derived here from the same accessors the walker's
        // published hints read, so the two cannot drift.
        //
        // The RANGE half needs none of this: with an array in the raw state,
        // isMultiple() answers true and Filament's own nested-recursive rules
        // (numeric/min:/max:/multiple_of: per element) already arrive through
        // childrenOf()'s `{$name}.*` machinery — Slider implements the same
        // HasNestedRecursiveValidationRules contract P7's TagsInput handling
        // reads. What the range shape still needs here is the CONTAINER rule:
        // `array` + `list`, the tags shape, so a crafted scalar or map can
        // never pose as the two-element List the contract declares. A scalar
        // submission flips isMultiple() false and gets the single bounds
        // instead — exactly Filament's own state-conditioned rule selection,
        // so mobile and web answer the same payload identically.
        if (ComponentTypeMap::for($component) === 'slider') {
            if (self::read($component, 'isMultiple') === true) {
                $rules = ['array', 'list'];
            } else {
                $rules[] = 'numeric';

                // The WithPadding variant first: rangePadding folds into the
                // enforced bound (vendor: the registered rules are built from
                // getMinValueWithPadding()/getMaxValueWithPadding()), and the
                // plain accessor is the fallback for a Filament line that
                // predates it.
                $min = self::read($component, 'getMinValueWithPadding')
                    ?? self::read($component, 'getMinValue');

                if (is_int($min) || is_float($min)) {
                    $rules[] = "min:{$min}";
                }

                $max = self::read($component, 'getMaxValueWithPadding')
                    ?? self::read($component, 'getMaxValue');

                if (is_int($max) || is_float($max)) {
                    $rules[] = "max:{$max}";
                }

                // `integer` when the step is exactly 1, `multiple_of:`
                // otherwise — vendor's own mapping, strict comparison and
                // all. A string or null step registers nothing, matching the
                // walker's "absence means any step".
                $step = self::read($component, 'getStep');

                if (is_int($step) || is_float($step)) {
                    $rules[] = $step === 1 ? 'integer' : "multiple_of:{$step}";
                }
            }
        }

        if (self::read($component, 'isRequired') === true) {
            $rules[] = 'required';
        }

        if (self::read($component, 'isEmail') === true) {
            $rules[] = 'email';
        }

        if (self::read($component, 'isNumeric') === true) {
            $rules[] = 'numeric';
        }

        // The three rules the write path never re-derived: a `->url()`,
        // `->regex(...)` or `->confirmed()` field 422'd on the web panel and
        // sailed through here — mobile looser than web, the one thing this
        // package's validation must never be. The published `rules` block
        // (SchemaWalker) carries the same three as client hints, so hint and
        // enforcement cannot drift.
        if (self::read($component, 'isUrl') === true) {
            $rules[] = 'url';
        }

        $pattern = self::read($component, 'getRegexPattern');

        if (is_string($pattern) && $pattern !== '') {
            $rules[] = "regex:{$pattern}";
        }

        // No accessor exists for `confirmed` — `->confirmed()` registers an
        // ordinary `rule('confirmed', $condition)`, so scanning the resolved
        // rule list is the only read. A closure that throws degrades to no
        // rule, the standing degrade-on-throw shape; the failure mode is a
        // missing constraint, not a crash, and it is documented in the
        // walker's own `confirmed` branch.
        $declared = self::read($component, 'getValidationRules');

        if (is_array($declared) && in_array('confirmed', $declared, true)) {
            $rules[] = 'confirmed';
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
