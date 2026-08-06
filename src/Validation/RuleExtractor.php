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
        return array_map(self::rulesFor(...), array_filter(
            self::leavesOf($components),
            static fn (object $component): bool => ! FieldPersistence::savesViaRelationship($component),
        ));
    }

    /**
     * The relation-write leaves — the components the controller's relation
     * pass saves — off the SAME descent as the rules, so "has no rule" and
     * "is saved as a relation" cannot drift apart. Disabled ones (and ones
     * whose disabled gate throws) are already dropped by the descent's
     * fail-closed refusal.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, object>
     */
    public static function relationWriteComponents(iterable $components): array
    {
        return array_filter(
            self::leavesOf($components),
            FieldPersistence::savesViaRelationship(...),
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

        foreach (self::leavesOf($components) as $name => $component) {
            $attribute = self::read($component, 'getValidationAttribute');

            if (is_string($attribute) && $attribute !== '') {
                $attributes[$name] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * The one descent both public methods read, name => the leaf component.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, object>
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
     * @return array<string, object>
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

        // A `file` field is the one type the walker publishes but the write
        // path must never accept. Upload is P6: the server has nowhere to put
        // bytes, the walker already emits `config.readOnly: true`, and a
        // client that submits the key anyway — by accident or on purpose —
        // would otherwise overwrite or *clear* a stored image with whatever
        // scalar it sent.
        //
        // Withholding the rule is what drops it: the rules array is also the
        // mass-assignment whitelist (only a validated key reaches create() or
        // update()), so no rule means no key, in store() and update() alike.
        // A second filter applied per controller method could drift between
        // the two; this cannot.
        //
        // The consequence is deliberate: this is the sole place the extractor
        // and the walker disagree on which names exist, and the pair of them
        // is otherwise held to exact parity by RuleExtractorTest.
        if ($type === 'file') {
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

        if (self::isNotSaved($component)) {
            return [];
        }

        $name = self::read($component, 'getName');

        if (! is_string($name) || $name === '') {
            return [];
        }

        return [$name => $component];
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
