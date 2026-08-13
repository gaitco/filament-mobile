<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Resources\RelationManagers\RelationManager;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\RelationCard;
use Gait\FilamentMobile\ResourceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use Throwable;

/**
 * A resource's relation managers, reduced to what the mobile contract can
 * faithfully serve — and a parallel list of the ones it refused, with the
 * reason, so `doctor` can name them.
 *
 * Every refusal here is the package's standing rule applied once more: a
 * relation this package cannot reproduce exactly is absent, never published
 * as an approximation. A list that quietly omits the panel's own narrowing
 * is worse than no list, because nothing on screen says it is incomplete.
 *
 * The card is resolved HERE rather than by each caller, and that is the
 * point: `/schema` and `RelationController` used to resolve it separately
 * and each decide for itself whether the result was usable, so "no card ⇒ no
 * relation" was enforced twice, as `$card === null`, and an empty but
 * non-null `MobileCard` — what `relationCard('key', fn ($card) => $card)`
 * builds — defeated both. One resolution means one answer, and it arrives
 * through the refusal list, so `doctor` names it instead of the relation
 * disappearing without a word.
 *
 * The table read goes through `HeadlessTableHost`, which is one of the three
 * files `tests/Unit/ArchitectureTest.php` permits to touch Filament's table
 * stack. This file must stay clean of it.
 */
final class RelationDiscovery
{
    /**
     * The published entries carry the child model class (`related`) and the
     * ONE mobile resource serving it (`resource`, null for zero or several)
     * alongside the read-side keys. Resolved here, once, so `/schema`'s
     * `resource` key and the write endpoints' 404 can never disagree about
     * whether a relation's rows are writable — the P6d card lesson applied
     * to P9's write capability.
     *
     * @return list<array{key: string, label: string, manager: class-string, columns: list<array{name: string, label: string}>, card: MobileCard, related: class-string|null, resource: class-string|null}>
     */
    public static function for(string $resourceClass, ?MobileResource $mobile = null, ?ResourceRegistry $registry = null): array
    {
        return self::split($resourceClass, $mobile, $registry)['published'];
    }

    /** @return array<string, string> class name => reason */
    public static function refusalsFor(string $resourceClass, ?MobileResource $mobile = null): array
    {
        return self::split($resourceClass, $mobile, null)['refused'];
    }

    /** @return array{published: list<array<string, mixed>>, refused: array<string, string>} */
    private static function split(string $resourceClass, ?MobileResource $mobile, ?ResourceRegistry $registry): array
    {
        $published = [];
        $refused = [];

        // Stateless (it reads the config/filament-mobile.resources list or the
        // panel, never request state), so a caller without one gets a fresh
        // instance answering identically.
        $registry ??= new ResourceRegistry();

        /** @var list<string> every relationship name read, published or not */
        $seen = [];

        $owner = self::modelInstance($resourceClass);

        foreach (self::entries($resourceClass) as $entry) {
            $name = is_string($entry) ? $entry : get_debug_type($entry);

            if (! is_string($entry) || ! is_subclass_of($entry, RelationManager::class)) {
                self::refuse($refused, $name, 'not a plain RelationManager subclass');

                continue;
            }

            try {
                $manager = new $entry;
                $key = $manager->getRelationshipName();
            } catch (Throwable $e) {
                self::refuse($refused, $name, 'relationship name could not be read: ' . $e->getMessage());

                continue;
            }

            if (! is_string($key) || $key === '') {
                self::refuse($refused, $name, 'relationship name could not be read');

                continue;
            }

            $seen[] = $key;

            // The child model class, for the `resource` resolution at the
            // bottom of the loop. Null when the resource has no instantiable
            // model — then there is no relationship to ask, and the write
            // side simply stays unpublished.
            $relatedClass = null;

            // On the resource's own model, not on an owner record: BUILDING a
            // relationship never queries, so an unsaved instance answers the
            // only question there is here — does this name resolve at all.
            // Left unasked, a `$relationship` naming nothing was PUBLISHED by
            // /schema and its endpoint then 403'd, because Filament's default
            // canViewForRecord cannot resolve the related model either. A
            // control that cannot work is exactly what this package refuses to
            // publish, and the 403 filed the panel's bug under "not for you".
            //
            // Skipped entirely when the resource has no instantiable model:
            // then there is no question to answer, only a resource that is
            // broken well upstream of its relations, and refusing every one of
            // them would report the wrong fault.
            if ($owner !== null) {
                try {
                    $resolved = $owner->{$key}();
                } catch (Throwable $e) {
                    self::refuse($refused, $name, "relationship [{$key}] does not resolve on " . class_basename($owner) . ': ' . $e->getMessage());

                    continue;
                }

                if (! $resolved instanceof Relation) {
                    self::refuse($refused, $name, "relationship [{$key}] does not resolve to an Eloquent relation on " . class_basename($owner));

                    continue;
                }

                $relatedClass = $resolved->getRelated()::class;
            }

            try {
                $table = HeadlessTableHost::relationTableFor($manager);
            } catch (Throwable $e) {
                self::refuse($refused, $name, 'table could not be built: ' . $e->getMessage());

                continue;
            }

            if ($table['narrowed']) {
                self::refuse($refused, $name, 'narrows its own query, which this package cannot reproduce outside Livewire');

                continue;
            }

            // The host's own card wins over the derived one, exactly as it
            // does for a resource: a derived card is a convenience, never an
            // override of what the panel author declared.
            $declared = $mobile?->getRelationCard($key);
            $card = $declared ?? RelationCard::fromColumns($table['columns']);

            if ($card === null) {
                self::refuse($refused, $name, "no card: its table declares no columns and the resource declares no relationCard('{$key}')");

                continue;
            }

            // Emptiness, not nullness. `fromColumns()` cannot produce a
            // slotless card (it returns null for zero columns and always sets
            // a title), so this only ever fires for a host declaration — and
            // it has to, because a card with no field paths serialises rows
            // carrying nothing but their record key and renders zero widgets.
            if ($card->fieldPaths() === []) {
                self::refuse($refused, $name, "relationCard('{$key}') fills no slot, so there is nothing to render");

                continue;
            }

            $published[] = [
                'key' => $key,
                'label' => self::labelFor($entry, $key),
                'manager' => $entry,
                'columns' => $table['columns'],
                'card' => $card,
                // The write capability (P9): exactly one mobile resource
                // serving the child model means the relation's rows have a
                // form to write against. Null — zero or several — keeps the
                // `resource` key off /schema and the write endpoints 404:
                // absence means unavailable, and guessing between two forms
                // would write one at random.
                'related' => $relatedClass,
                'resource' => $relatedClass === null ? null : $registry->findByModel($relatedClass),
            ];
        }

        foreach (self::strayCardKeys($mobile, $seen) as $stray) {
            self::refuse($refused, $stray, "relationCard('{$stray}') matches no relation on this resource — check it against getRelations()");
        }

        return ['published' => $published, 'refused' => $refused];
    }

    /**
     * `relationCard()` keys naming no relation manager this resource declares
     * — a typo, or a relation since renamed. The declaration is inert and the
     * derived card is used instead, which is the safe direction but a silent
     * one: the author's card was simply never read.
     *
     * Against every name `getRelationshipName()` answered, published or
     * refused, so overriding the card of a relation refused for narrowing is
     * not reported as a typo it isn't.
     *
     * @param  list<string>  $seen
     * @return list<string>
     */
    private static function strayCardKeys(?MobileResource $mobile, array $seen): array
    {
        return array_values(array_diff($mobile?->getRelationCardKeys() ?? [], $seen));
    }

    /**
     * An unsaved instance of the resource's model, purely to build (never to
     * run) each relationship on. Null when the resource has no model this
     * process can instantiate — an ordinary shape in this suite's inline test
     * resources, which never declare `$model` and so inherit Filament's
     * `App\Models\…` guess.
     */
    private static function modelInstance(string $resourceClass): ?Model
    {
        try {
            $model = new ($resourceClass::getModel())();
        } catch (Throwable) {
            return null;
        }

        return $model instanceof Model ? $model : null;
    }

    /**
     * Records one refusal without displacing another.
     *
     * `doctor` is the only channel that tells a panel author why a relation
     * vanished, so a refusal that overwrites an earlier one is a relation
     * that disappears in silence. Class basenames collided across namespaces
     * and every `RelationGroup` shared one key, so entries are named by full
     * class name and a genuine repeat — two groups on one resource — is
     * suffixed rather than dropped.
     *
     * @param  array<string, string>  $refused
     */
    private static function refuse(array &$refused, string $name, string $reason): void
    {
        $key = $name;

        for ($n = 2; isset($refused[$key]); $n++) {
            $key = $name . ' #' . $n;
        }

        $refused[$key] = $reason;
    }

    /**
     * A resource that cannot list its own relations has none, rather than
     * breaking the document it appears in.
     *
     * @return iterable<mixed>
     */
    private static function entries(string $resourceClass): iterable
    {
        try {
            $relations = $resourceClass::getRelations();
        } catch (Throwable) {
            return [];
        }

        return is_array($relations) ? $relations : [];
    }

    /**
     * The heading the panel gives this relation, or the humanised relationship
     * name.
     *
     * `getTitle()` is the only accessor that reaches all three of the panel's
     * own naming routes — an explicit `$title`, a `$relationshipTitle`, and a
     * related resource's plural label — and measured on a bare, never-mounted
     * manager it answers all of them. It insists on an owner record it does
     * not read, so it gets a throwaway one. (`getModel()`, which an earlier
     * draft of this method called, does not exist on a RelationManager at all
     * — it is a `Resource` accessor.)
     *
     * "Does not read" holds only for the INHERITED body,
     * `static::$title ?? static::getRelationshipTitle()`. An override that
     * genuinely reads the record mostly does not throw against a throwaway —
     * Eloquent answers null for an unset attribute and `getKey()` answers
     * null on an unsaved model — so `'Banners for ' . $ownerRecord->name`
     * published the heading `Banners for `. A truncated string presented as
     * the panel's own answer is the approximation this file exists to refuse,
     * so an overridden `getTitle()` is not called at all: without a real
     * owner record there is no honest answer, and the humanised relationship
     * name is at least true. `$title` is a property override, not a method
     * one, so declaring it still wins.
     */
    private static function labelFor(string $manager, string $key): string
    {
        try {
            $title = (new ReflectionMethod($manager, 'getTitle'))->getDeclaringClass()->getName() === RelationManager::class
                ? $manager::getTitle(new class extends Model {}, '')
                : null;
        } catch (Throwable) {
            $title = null;
        }

        return is_string($title) && $title !== ''
            ? $title
            : str($key)->headline()->toString();
    }
}
