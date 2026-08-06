<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Introspection\SafeEvaluator;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\WalkWarnings;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;
use UnitEnum;

final class PanelSchemaBuilder
{
    private WalkWarnings $warnings;

    public function __construct(private readonly ResourceRegistry $registry)
    {
        $this->warnings = new WalkWarnings();
    }

    /** @return array<string, mixed> */
    public function build(?Authenticatable $user): array
    {
        $this->warnings = new WalkWarnings();
        $walker = new SchemaWalker($this->warnings);
        $resources = [];

        foreach ($this->registry->mobileResources() as $class => $mobile) {
            // Authorization is the panel's existing policy, never a second
            // permission model. A resource the user cannot view in the web
            // panel is absent here, not merely hidden.
            if (! $this->allows($user, 'viewAny', $class::getModel())) {
                continue;
            }

            $resources[] = $this->resource($class, $mobile, $walker, $user);
        }

        return [
            'version' => 1,
            'panel' => [
                'id' => 'mobile',
                'title' => config('app.name'),
            ],
            'resources' => $resources,
        ];
    }

    public function warnings(): WalkWarnings
    {
        return $this->warnings;
    }

    /** @return array<string, mixed> */
    private function resource(
        string $class,
        MobileResource $mobile,
        SchemaWalker $walker,
        ?Authenticatable $user,
    ): array {
        $model = $class::getModel();
        $short = class_basename($class);
        $key = $this->registry->keyFor($class);

        $block = [
            'key' => $key,
            'labels' => [
                'singular' => $class::getModelLabel(),
                'plural' => $class::getPluralModelLabel(),
            ],
            // These five are *capability*, not authorization. `viewAny` and
            // `create` are exact — they are class-level questions a policy can
            // answer. `view`, `update` and `delete` mean "this resource
            // supports the action and this user is not categorically barred
            // from it", which is what a client needs to decide whether to
            // render the affordance at all. They are deliberately NOT a
            // per-record answer: the common ownership policy
            // (`update(User $user, Post $post) => $post->user_id === $user->id`)
            // has no answer without a record, so asking it here would either
            // crash or invent a "no" that hides the read path. The per-record
            // truth travels with each record, computed against the real model.
            'permissions' => [
                'viewAny' => $this->allows($user, 'viewAny', $model),
                'view' => $this->allows($user, 'view', $model),
                'create' => $this->allows($user, 'create', $model),
                'update' => $this->allows($user, 'update', $model),
                'delete' => $this->allows($user, 'delete', $model),
            ],
            'recordKey' => (new $model())->getRouteKeyName(),
            'card' => $mobile->getCard()->toArray(),
            'search' => [
                'enabled' => $mobile->getSearchable() !== [],
            ],
            'sorts' => $mobile->sortsToArray(),
            // Always empty in P1, and not an uncovered branch — there is no
            // branch. Filters are declared on Filament's *table*, which this
            // package deliberately never reads (see the architecture test), so
            // there is nothing to introspect and nothing to test. The key stays
            // because the contract defines it and the client expects a list.
            'filters' => [],
            'form' => $walker->walk($this->schemaComponents($class, 'form'), $short, $key),
            'infolist' => $walker->walk($this->schemaComponents($class, 'infolist'), $short, $key),
        ];

        // string | UnitEnum | null — Filament permits a backed enum here
        // (HasNavigation.php:105), and a naive (string) cast fatals on it.
        // Absent rather than null when there is none: a null would add a key
        // to every resource in a panel that groups nothing.
        $group = $this->groupOf($class);

        if ($group !== null) {
            $block['group'] = $group;
        }

        return $block;
    }

    /**
     * Reads `getNavigationGroup()` defensively — same reason as every other
     * accessor this package reads, see SafeEvaluator — and resolves a
     * `UnitEnum` group to the label a client can render: `getLabel()` when it
     * implements Filament's `HasLabel`, else `->value` for a backed enum,
     * else `->name`. Anything that cannot become a non-empty string (an
     * accessor that throws, an empty label) comes back null.
     *
     * @param  class-string  $class
     */
    private function groupOf(string $class): ?string
    {
        $evaluator = new SafeEvaluator($this->warnings);

        return $evaluator->value(
            function () use ($class): ?string {
                $group = $class::getNavigationGroup();

                if ($group instanceof UnitEnum) {
                    $group = match (true) {
                        $group instanceof HasLabel => $group->getLabel(),
                        $group instanceof BackedEnum => $group->value,
                        default => $group->name,
                    };
                }

                return is_string($group) && $group !== '' ? $group : null;
            },
            null,
            class_basename($class),
            'resource',
            'navigationGroup',
        );
    }

    /**
     * @param  class-string  $model
     */
    private function allows(?Authenticatable $user, string $ability, string $model): bool
    {
        // Shared with the endpoints — see Authorizer for why a missing policy
        // is an allow.
        return Authorizer::allows($user, $ability, $model);
    }

    /**
     * `getComponents()` (not `getDefaultChildComponents()`) is right here: it
     * wires the container and normalizes string children before the walker
     * sees them.
     *
     * `withHidden: true` and the host together close one root cause. Left to
     * itself, `getComponents()` filters on `isHidden()` during *construction*,
     * outside SafeEvaluator's reach: the first `visible(fn (Get $get) => ...)`
     * in a real form fatals on `Schema::getLivewire()`, whose return type is
     * not nullable, and takes the whole resource's form down to the
     * empty-schema fallback below. Skipping that filter removes the crash, and
     * an unseeded host answers every `$get()` with null so the surviving
     * `hidden` flags are the empty-form snapshot /schema is defined to be — a
     * first-paint hint, with /state authoritative once the user types.
     *
     * It also means /schema and /state publish the SAME component set: a
     * conditional field is present in both, differing only in `hidden`. A
     * client that never saw the field could not lay out the form until its
     * first /state round-trip.
     *
     * `->model()` is what Filament's own resource pages set, and without it a
     * `Select::relationship()` cannot resolve its options: `getRelationship()`
     * reaches for the schema's model instance and fails on null. The pilot
     * measured that as an empty `options` array on 6 resources' required
     * foreign keys — a picker with nothing in it, which no client can submit.
     *
     * @param  class-string  $class
     * @return list<object>
     */
    private function schemaComponents(string $class, string $method): array
    {
        try {
            return $class::{$method}(
                Schema::make(new HeadlessSchemaHost())
                    ->model($class::getModel())
                    // Unset, `getOperation()` falls through to
                    // `getLivewire()::class` and matches nothing inside
                    // `disabledOn()`/`hiddenOn()`/`visibleOn()`, so every
                    // operation gate answers false — the flag is then not
                    // advisory, it is arbitrary. `create` is the honest label
                    // for a document built with no record, and it is the screen
                    // a client renders first. `/state` re-answers with the real
                    // operation once a record is in play.
                    //
                    // No `->record()` on this path, deliberately: see
                    // MobilePanelController::infolistPaths().
                    ->operation($method === 'form' ? 'create' : 'view'),
            )->getComponents(withHidden: true);
        } catch (Throwable $e) {
            // SafeEvaluator guards the walk, but not the *construction* of the
            // schema being walked, and that is where a real panel breaks: a
            // closure typed against the record — `->visible(fn (Order $record)
            // => ...)`, `->schema(fn (Order $record) => ...)` — is evaluated by
            // getComponents() and fatals with "Argument #1 ($record) must be of
            // type Order, null given" when there is no record, which is always
            // the case for /schema.
            //
            // The pilot hit this on 2 of 35 resources. Unguarded, either one
            // turns the whole /schema request into a 500 — one resource taking
            // down the entire panel document is exactly the failure the
            // per-property degradation elsewhere exists to prevent. The
            // resource keeps its card, sorts and permissions; only the schema
            // it could not build comes back empty, with a warning naming it.
            $this->warnings->add(
                class_basename($class),
                $method . '()',
                'could not build the ' . $method . ' schema: ' . $e->getMessage(),
            );

            return [];
        }
    }
}
