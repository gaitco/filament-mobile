<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use ReflectionException;
use ReflectionProperty;
use Throwable;

/**
 * "Would Filament itself persist this component's value?"
 *
 * One predicate with one home, because there are two ways into the database
 * from a mobile write — the validated payload (RuleExtractor) and the form's
 * own defaults (FormDefaults) — and a guard applied to only one of them is not
 * a guard. The first version of this check lived in RuleExtractor alone, and
 * review found `Hidden::make('x')->hidden()->default('leak')` walking straight
 * past it through the defaults path.
 *
 * Both accessors matter and neither implies the other. Filament does not fold
 * `isDisabled()` into `isDehydrated()`, and a disabled field it *does* save is
 * saving the value the server filled in — which a browser cannot change and an
 * HTTP client can. Over the wire those are not the same guarantee, so disabled
 * refuses even against an explicit `->dehydrated()`.
 */
final class FieldPersistence
{
    /**
     * ponytail: answers per component, against whatever state the schema was
     * built with. On a write that state is no longer whatever the client
     * submitted — it is settled first, through `Write\SettledSchema`, so every
     * gate this method answers is evaluated against state no crafted payload
     * could have steered.
     *
     * `SettledSchema` gets there by iterating: a submitted value survives a
     * pass only if the schema built from it will WRITE that name, and the
     * loop keeps re-narrowing because dropping one name can close a SECOND
     * name's gate while that second name's crafted value was still sitting in
     * the state that closed it — one pass is not enough to see that. The
     * allow-set only ever shrinks, so it stabilises, and the state it settles
     * to is reset from a trusted floor: the stored record's cast attributes on
     * `update()`, and `FormDefaults::fromComponents()` over an EMPTY state on
     * `store()` (there is no record yet, so there is nothing else honest to
     * reset to). `/state` settles the same way, for the same reason `store()`
     * and `update()` must agree with each other: a form that draws itself
     * editable and then has the write refuse the field is a control whose
     * input the client never learns was discarded.
     */
    public static function refuses(object $component): bool
    {
        return self::refusedBy($component, 'isDisabled', true)
            || self::refusedBy($component, 'isDehydrated', false);
    }

    /**
     * The disabled half of refuses() alone — for a component that
     * savesViaRelationship(), whose dehydration is false BY DESIGN and must
     * not count against it, while a disabled gate (or one that throws)
     * still refuses, fail closed.
     */
    public static function refusesDisabled(object $component): bool
    {
        return self::refusedBy($component, 'isDisabled', true);
    }

    /**
     * "Would this component be refused no matter what the client types?"
     *
     * The publishable half of refuses(). /schema derived `disabled` from
     * `isDisabled()` alone, so a field the write path always drops was
     * published editable: `/schema` said `disabled: false` for
     * `dehydrated(false)`, `POST` answered 201, and the column stayed NULL.
     * The user's typing was discarded behind a success response.
     *
     * It is deliberately NOT all of refuses(). A refusal that depends on
     * submitted state is not a locked field, it is a field nobody has filled
     * in yet: `dehydrated(fn ($state) => filled($state))` — the idiom that
     * protects a stored password — is false on the empty form /schema walks,
     * and publishing that as `disabled` would render the password field
     * read-only and make it unsettable from a phone forever. Infolist entries
     * are the same shape from the other side: `Entry::isDehydrated()` is a
     * hard `false` because an entry displays rather than saves, and its
     * `disabled` flag is about presentation. Neither may flip.
     *
     * So only two refusals are published, and both are facts about the
     * component rather than about the payload:
     *
     *  - the dehydration condition is the literal `false` that
     *    `->dehydrated(false)` stores, which no state can turn true;
     *  - the condition threw on a component that is wired into a Schema,
     *    i.e. a gate that cannot answer — which refuses on the write path
     *    (see refusedBy) and must therefore read as locked.
     *
     * ponytail: a dehydration *closure* that resolves false from component
     * configuration alone — `Select::multiple()->relationship()` compiles to
     * `fn ($component) => (! $component->isMultiple()) && $component->isSaved()`
     * — is still published editable. Through Filament's public API that false
     * is indistinguishable from the `filled($state)` false above; separating
     * them means either probing the component with synthetic state or
     * reflecting the closure's parameter list to guess what it reads, and
     * both are worse than the gap. Single-valued relationship selects, the
     * common case, dehydrate normally and are unaffected. Revisit if a panel
     * shows it hurting.
     */
    public static function neverPersists(object $component): bool
    {
        if (self::savesViaRelationship($component)) {
            // Saved through saveRelationships() on the write path — editable,
            // whatever its dehydration says: Select::multiple()->relationship()
            // compiles dehydrated() to a closure that is false by design.
            return false;
        }

        if (! method_exists($component, 'isDehydrated')) {
            return false;
        }

        try {
            if ($component->isDehydrated()) {
                return false;
            }
        } catch (Throwable) {
            return self::isWired($component);
        }

        return self::isUndehydratedByConfiguration($component);
    }

    /**
     * A multi-valued relationship field — saved by the controller's
     * relation pass (`saveRelationships()`), never through the model's
     * attributes. RuleExtractor withholds its rule so it can never enter
     * mass assignment, and WritableNames admits its name so the settle
     * does not strip the submitted ids before the relation pass reads them.
     *
     * This is a fact about the component, answered through Filament's public
     * API — unlike a dehydration CLOSURE, which resolves false for reasons
     * this package cannot inspect and is therefore left published as editable
     * and documented as a residual.
     *
     * "No isMultiple()" is NOT "assume multiple" — the first version of this
     * method read it that way, and it locked more than the relationship
     * select this method exists for. `Section`, `Group`, `Grid`, `Fieldset`,
     * `Flex` and `Form` all expose `getRelationship()` through
     * `EntanglesStateWithSingularRelationship`, none has `isMultiple()`
     * either, and that trait resolves only `BelongsTo|HasOne|MorphOne` — its
     * name says singular and its return type enforces it. Reachable with
     * `Section::make(...)->relationship('company')` wired into a real Schema,
     * which is the case review found: `getRelationship()` came back a
     * non-null `BelongsTo` and the fallback locked a container the write path
     * saves the ordinary way.
     *
     * Two components genuinely lack `isMultiple()` while being multi-valued,
     * so they are named explicitly rather than inferred from an absent method
     * — by class name, not `instanceof`, matching ComponentTypeMap's own
     * convention so this file still never imports a concrete Filament
     * component class:
     *
     *  - `CheckboxList`, multi-valued by nature;
     *  - `Repeater` (P9), multi-valued when — and only when — it declares
     *    `->relationship()`: its rows are child records written by Filament's
     *    own `Repeater::saveToRelationship()` through the relation pass,
     *    never an attribute on the parent. A plain JSON-column repeater has
     *    no relationship, so the `getRelationship() !== null` check below
     *    still answers false for it and it stays an ordinary column write.
     *    Before this branch a relationship repeater read as a SINGULAR
     *    relationship container (no `isMultiple()`), so `neverPersists()`
     *    locked it on its literal `dehydrated(false)` — and an explicit
     *    `->dehydrated(true)` overrode even that, admitting the name as a
     *    column write that only `refusesRelationship()` kept from 500ing.
     *
     * A relationship-type test (`BelongsToMany`/`MorphToMany` vs.
     * `BelongsTo`/`HasOne`/`MorphOne`) was the other candidate and was
     * rejected: `Select::relationship()` also drives a genuinely multi-valued
     * select off a plain `HasMany` (see `fillStateFromRelationship()`'s
     * `instanceof HasMany` branch, distinct from `BelongsToMany`), so that
     * test would silently readmit the very data-loss case this method exists
     * to catch. Component identity does not have that gap.
     */
    public static function savesViaRelationship(object $component): bool
    {
        if (! method_exists($component, 'getRelationship')) {
            return false;
        }

        if ($component::class === 'Filament\\Forms\\Components\\CheckboxList'
            || $component::class === 'Filament\\Forms\\Components\\Repeater') {
            // Multi-valued without an isMultiple() — a CheckboxList by
            // nature, a Repeater when it declares ->relationship(). A plain
            // JSON-column repeater fails the getRelationship() check below
            // and stays a column write. See the docblock above.
            $multiple = true;
        } elseif (method_exists($component, 'isMultiple')) {
            $multiple = $component->isMultiple();
        } else {
            // A singular relationship container (Section, Group, Grid,
            // Fieldset, Flex, Form) — see the docblock above.
            $multiple = false;
        }

        if (! $multiple) {
            return false;
        }

        try {
            return $component->getRelationship() !== null;
        } catch (Throwable) {
            // A relationship name pointing at a method the model lacks throws
            // here. Refusing is the safe answer: an unresolvable relationship
            // is certainly not one this package can save.
            return true;
        }
    }

    /**
     * Whether a repeater's rows belong to a RELATIONSHIP rather than to a
     * column of the record — or whether that cannot be answered at all, which
     * refuses the same way.
     *
     * Asked only of a repeater. It is deliberately not a general predicate: a
     * component with no `getRelationship()` at all lands in the refusal
     * ("nothing declared these rows writable"), which is the right answer for
     * a repeater and the wrong one for a TextInput.
     *
     * Since P9 a TRUE answer is no longer a refusal on its own: a resolvable
     * relationship repeater is writable, saved by the controller's relation
     * pass (see savesViaRelationship()'s Repeater branch). What still refuses
     * is the gate that cannot ANSWER — a throwing `relationship()` closure,
     * reported through `$error` — and both remaining callers key on that:
     * SchemaWalker publishes `config.readOnly` only when `$error` is set, and
     * RuleExtractor withholds the field's rules and writable name on the same
     * condition, so the published flag and the write path cannot disagree.
     * They DID disagree before this predicate existed:
     * `Repeater::relationship()->dehydrated(true)` overrode the literal-false
     * dehydration `savesViaRelationship()` misclassified as singular, so the
     * node said `readOnly: true` while `WritableNames` admitted the name, and
     * a crafted payload reached `update()` as a column that does not exist —
     * a QueryException, i.e. a 500 on crafted input. (`withheldChild()` also
     * calls this, for a repeater nested in an item template: a nested
     * RELATIONSHIP repeater still refuses there — two levels of row
     * coordinate through the relation pass is a different problem.)
     *
     * `$error` is an out-parameter rather than a second method so the walker
     * can warn — with the real message — about a gate that errored, without
     * evaluating the gate twice.
     */
    public static function refusesRelationship(object $component, ?Throwable &$error = null): bool
    {
        $error = null;

        if (! method_exists($component, 'getRelationship')) {
            return true;
        }

        try {
            return $component->getRelationship() !== null;
        } catch (Throwable $e) {
            $error = $e;

            return true;
        }
    }

    /**
     * Whether `->dehydrated(false)` was called with a literal `false` rather
     * than a closure.
     *
     * Reflection, unlike SchemaWalker's former `hasContainer()`, because here
     * there is no public equivalent to consolidate onto: Filament exposes the
     * *resolved* answer through `isDehydrated()` and never the raw condition,
     * and the resolved answer is exactly what cannot tell the two apart. It
     * fails open — a Filament release that renames the property publishes what
     * it publishes today rather than locking every field — and the contract
     * snapshot, which runs on the Filament 4 and 5 matrix, is what turns that
     * rename red.
     */
    private static function isUndehydratedByConfiguration(object $component): bool
    {
        try {
            $condition = new ReflectionProperty($component, 'isDehydrated');
        } catch (ReflectionException) {
            return false;
        }

        return $condition->isInitialized($component)
            && $condition->getValue($component) === false;
    }

    /**
     * Fails open for a component that was never wired into a Schema and closed
     * for everything else.
     *
     * The distinction is the whole point. A bare component — a unit-test
     * fixture, a leaf read before the tree is assembled — throws on every
     * container-backed accessor; that is structural, and refusing every field
     * on it would make the extractor emit nothing at all. A component that IS
     * wired up and still throws is a gate that errored, and review found the
     * exact shape: `disabled(fn (Model $record) => $record->locked)` with a
     * non-nullable hint TypeErrors when there is no record, which made the
     * *stricter* signature the more exposed one. A gate that cannot answer
     * must refuse the field, never admit it.
     *
     * The question is asked of the *component*, never of the exception's
     * message. The first version of this carve-out matched
     * `'$container must not be accessed'` in the message text, and review
     * broke it in one request: an ordinary gate like
     * `disabled(fn (Get $get) => Model::findOrFail($get('kind'))->locked)`
     * raises a ModelNotFoundException that embeds the submitted value
     * verbatim, so `{"kind": "nope, $container must not be accessed"}`
     * readmitted the original bug on a payload the attacker composes. Whether
     * a component is wired into a Schema is a fact about the object; nothing a
     * client sends can change the answer.
     */
    private static function refusedBy(object $component, string $method, bool $refuseWhen): bool
    {
        if (! method_exists($component, $method)) {
            return false;
        }

        try {
            return $component->{$method}() === $refuseWhen;
        } catch (Throwable) {
            return self::isWired($component);
        }
    }

    /**
     * Whether this component is attached to a Schema — i.e. whether a gate on
     * it could have been evaluated at all.
     *
     * Public because SchemaWalker asks the same question and used to answer it
     * with `ReflectionProperty($component, 'container')` — a *private* Filament
     * property, reached around a public accessor that answers identically.
     */
    public static function isWired(object $component): bool
    {
        if (! method_exists($component, 'getContainer')) {
            return false;
        }

        try {
            $component->getContainer();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
