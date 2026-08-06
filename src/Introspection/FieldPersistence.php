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
        if (self::isMultiValuedRelationship($component)) {
            return true;
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
     * A multi-valued relationship field can never be written.
     *
     * MobilePanelController writes with Model::create()/$record->update() and
     * never calls Filament's saveRelationships(), so a multiple relationship
     * select returns 201 having attached nothing.
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
     * saves the ordinary way. `CheckboxList` is the one component that
     * genuinely lacks `isMultiple()` and IS multi-valued regardless, so it is
     * named explicitly rather than inferred from an absent method — by class
     * name, not `instanceof`, matching ComponentTypeMap's own convention so
     * this file still never imports a concrete Filament component class.
     *
     * A relationship-type test (`BelongsToMany`/`MorphToMany` vs.
     * `BelongsTo`/`HasOne`/`MorphOne`) was the other candidate and was
     * rejected: `Select::relationship()` also drives a genuinely multi-valued
     * select off a plain `HasMany` (see `fillStateFromRelationship()`'s
     * `instanceof HasMany` branch, distinct from `BelongsToMany`), so that
     * test would silently readmit the very data-loss case this method exists
     * to catch. Component identity does not have that gap.
     */
    private static function isMultiValuedRelationship(object $component): bool
    {
        if (! method_exists($component, 'getRelationship')) {
            return false;
        }

        if ($component::class === 'Filament\\Forms\\Components\\CheckboxList') {
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
