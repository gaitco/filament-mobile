<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Filament\Facades\Filament;
use Gait\FilamentMobile\Introspection\FormDefaults;
use Gait\FilamentMobile\Introspection\MediaFields;
use Gait\FilamentMobile\Introspection\RelationDiscovery;
use Gait\FilamentMobile\Introspection\RichContentAdapter;
use Gait\FilamentMobile\Introspection\TagFields;
use Gait\FilamentMobile\Introspection\TagSeparatorAdapter;
use Gait\FilamentMobile\Introspection\TagSeparators;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Write\RecordForm;
use Gait\FilamentMobile\Write\WritableNames;
use Gait\MobileCore\Authorizer;
use Gait\MobileCore\ListQuery;
use Gait\MobileCore\RecordSerializer;
use Gait\MobileCore\SettledSchema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One relation manager's child rows: a paginated read, and — since P9 —
 * create/update/delete of individual rows.
 *
 * The parent is resolved from the URL and authorized before the relation is
 * touched — a relation is never scoped by a client-supplied parent id, which
 * is the whole reason this is a nested route rather than a filter on
 * `index()`. A child row is resolved THROUGH the relationship for the same
 * reason: a child of a different parent is a 404, never a cross-parent write.
 *
 * Every method runs the same gate sequence (resolve()), so the read and the
 * writes cannot disagree about whether a relation exists or who may reach it.
 */
final class RelationController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly PanelSchemaBuilder $builder,
    ) {
    }

    public function __invoke(Request $request, string $resource, string $id, string $relation): JsonResponse
    {
        [, $published, $record, $relationship, $related, $mobile] = $this->resolve($request, $resource, $id, $relation);

        // Gate 3 — the child model's own viewAny. Redundant with Filament's
        // DEFAULT canViewForRecord (which calls authorize('viewAny', $model)),
        // and not redundant with an overridden one, which replaces that check
        // rather than adding to it. Read-only: the write endpoints gate on the
        // child's create/update/delete abilities instead, per operation.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $related::class),
            403,
        );

        // THE published card — the one /schema resolved, not a second
        // resolution that could disagree with it. Cardless is how /schema
        // omits a relation entirely, so the endpoint for one was never
        // published either and the 404 above has already fired.
        $card = $published['card'];

        // Same reasoning as index()'s own mediaPaths computation: an undotted
        // media path (e.g. a card-bound `cover`) never appears in
        // `relationPaths()`, so without an explicit `media` eager load it
        // lazy-loads once per row here too.
        $cardMediaPaths = $this->cardMediaPaths($published['resource'], $card);

        // Same reasoning, for the tags read-path twin of the media N+1
        // defence above.
        $cardTagPaths = $this->cardTagPaths($published['resource'], $card);

        // P11: the index's search/sort, against THIS relation's declarations
        // and strictly after the last gate — a 422 for a bad parameter must
        // never outrun a 403/404 and confirm the relation exists. The
        // relationship's own builder, so the where group lands inside the
        // relationship constraint rather than beside it.
        $query = $relationship->getQuery();

        ListQuery::applySearch($query, ListQuery::stringQuery($request, 'search'), $mobile->getRelationSearchable($relation));
        ListQuery::applySort(
            $query,
            $request,
            $mobile->getRelationSorts($relation),
            $mobile->getRelationDefaultSortKey($relation),
            $mobile->getRelationDefaultSortDirection($relation),
        );

        // The same N+1 defence `index()` applies, for the same reason and
        // from the same source: `relationPaths()` is derived from the card's
        // dotted fields, so a `subtitle('company.name')` costs one query for
        // the page instead of one per row. `index()` has had this since P1 and
        // this endpoint — documented as mirroring it "exactly" — did not:
        // measured at 14 queries for 10 rows against index()'s 4.
        // Same reasoning as MobilePanelController::index()'s own guard:
        // cardMediaPaths()/cardTagPaths() are schema-only and survive on a
        // model without HasMedia/HasTags, so the eager load itself needs the
        // trait check — `$related` is already the child model instance this
        // relation resolved, from the same reach `resolve()` uses.
        $records = $relationship
            ->with($card->relationPaths())
            ->when($cardMediaPaths !== [] && method_exists($related, 'getMedia'), fn ($query) => $query->with('media'))
            ->when($cardTagPaths !== [] && method_exists($related, 'syncTagsWithType'), fn ($query) => $query->with('tags'))
            ->paginate(config('filament-mobile.per_page'));

        // The child's OWN resource, not this one: a column's shape is declared
        // by the resource that writes it (a `TagsInput->separator(',')` stores
        // a delimited string — see TagSeparators), and that is `BannerResource`
        // for a banner, whichever company happens to list it. Fix round 2 of
        // P7 Task 3: this seam passed nothing, on the wrong reasoning that a
        // relation "has no form", and published `"a,b"` where every other seam
        // published `["a","b"]` — reachable without a hand-declared card,
        // because RelationCard derives one from the manager's columns.
        //
        // `$published['resource']` — the ONE discovery answer, which /schema
        // also publishes — rather than a second findByModel() call here, so
        // the read's serializer and the write capability cannot drift. Null
        // when no mobile resource serves the child model or when several do,
        // and null is the pre-existing behaviour (the raw stored value). See
        // ResourceRegistry::findByModel().
        $serializer = (new RecordSerializer(
            $card,
            $related->getRouteKeyName(),
            $published['resource'],
            new RichContentAdapter(),
            new TagSeparatorAdapter(),
        ))->withMediaPaths($cardMediaPaths)
            ->withTagPaths($cardTagPaths);

        return response()->json([
            'data' => array_map(
                static fn (Model $child): array => $serializer->serialize($child),
                $records->items(),
            ),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * Create a child row through the relationship: 201 + the serialized row
     * in the relation envelope's row shape.
     *
     * The form is the CHILD resource's own, reused whole, and the write runs
     * the identical machinery store() runs — SettledSchema, the rules as the
     * mass-assignment whitelist, the panel's defaults under the payload,
     * TagSeparators at the seam, and the relation pass for relation-write
     * fields — through RecordForm, the one home both controllers share.
     */
    public function store(Request $request, string $resource, string $record, string $relation): JsonResponse
    {
        [$childClass, $published, , $relationship, $related] = $this->resolve($request, $resource, $record, $relation, forWrite: true);

        // Class-level, not per-record: there is no child record yet. Same
        // ruling store() makes — see Authorizer::allows() vs allowsRecord().
        abort_unless(
            Authorizer::allows($request->user(), 'create', $related::class),
            403,
        );

        $settled = SettledSchema::settle(
            submitted: $request->all(),
            // The child form's own defaults are the trusted state on create,
            // from an EMPTY state — exactly as store() settles, for exactly
            // its reasons.
            trusted: FormDefaults::fromComponents(
                RecordForm::components($childClass, [], record: null),
            ),
            build: function (array $state) use ($childClass): array {
                $components = RecordForm::components($childClass, $state, record: null);

                return ['components' => $components, 'writable' => WritableNames::of($components)];
            },
        );

        $components = $settled->components();

        $validated = $request->validate(
            RecordForm::rules($settled),
            attributes: RecordForm::validationAttributes($settled),
        );

        // Through the relationship, so the foreign key (or the pivot) is the
        // parent's by construction — a row is never created floating and then
        // checked for membership after the fact.
        $child = $relationship->create(TagSeparators::dehydrate(
            RecordForm::fillMissingPaths($validated, FormDefaults::fromComponents($components)),
            $components,
        ));

        RecordForm::saveRelations($childClass, $settled->state(), $child, 'create', $request->all());

        return response()->json(
            ['data' => $this->rowSerializer($published, $related)->serialize($child)],
            201,
        );
    }

    /**
     * Update a child row: 200 + the serialized row. The `{child}` segment is
     * the RELATED model's own route key (the relation's published
     * `recordKey`), resolved through the relationship — a child that does not
     * belong to this parent is a 404, never a cross-parent write.
     */
    public function update(Request $request, string $resource, string $record, string $relation, string $child): JsonResponse
    {
        [$childClass, $published, , $relationship, $related] = $this->resolve($request, $resource, $record, $relation, forWrite: true);

        $childRecord = $this->resolveChild($relationship, $related, $child);

        // Against the loaded child, never the class: update is authorization,
        // not capability. Same ruling update() makes.
        abort_unless(
            Authorizer::allowsRecord($request->user(), 'update', $childRecord),
            403,
        );

        // The child's stored values sit under the payload, exactly as
        // update() settles: a partial PUT that does not resend a sibling must
        // still resolve gates that read it.
        $settled = SettledSchema::settle(
            submitted: [...$childRecord->attributesToArray(), ...$request->all()],
            trusted: $childRecord->attributesToArray(),
            build: function (array $state) use ($childClass, $childRecord): array {
                $components = RecordForm::components($childClass, $state, $childRecord);

                return ['components' => $components, 'writable' => WritableNames::of($components)];
            },
        );

        $rules = RecordForm::rules($settled);

        $validated = $request->validate(
            $rules,
            attributes: RecordForm::validationAttributes($settled),
        );

        // The same dotted-path fill and TagSeparators mirror update()
        // applies, at what is now the fourth write seam — see RecordForm.
        $childRecord->update(TagSeparators::dehydrate(
            RecordForm::fillMissingPaths($validated, RecordForm::storedPaths($childRecord, array_keys($rules))),
            $settled->components(),
        ));

        RecordForm::saveRelations($childClass, $settled->state(), $childRecord, 'edit', $request->all());

        return response()->json(
            ['data' => $this->rowSerializer($published, $related)->serialize($childRecord)],
        );
    }

    /**
     * Delete a child row: 200 with the deleted row's serialized form, so the
     * client can confirm exactly what it removed without a re-fetch. (The
     * resource-level destroy() answers 204 with no body — a deliberate
     * divergence: the relation client holds a LIST it must reconcile, and
     * the design spec fixes this shape.)
     *
     * The model's delete, NOT Filament's DeleteAction — the same known delta
     * destroy() documents: Livewire hooks do not run here, observers, soft
     * deletes and cascades do, and the policy was already checked above.
     */
    public function destroy(Request $request, string $resource, string $record, string $relation, string $child): JsonResponse
    {
        [, $published, , $relationship, $related] = $this->resolve($request, $resource, $record, $relation, forWrite: true);

        $childRecord = $this->resolveChild($relationship, $related, $child);

        abort_unless(
            Authorizer::allowsRecord($request->user(), 'delete', $childRecord),
            403,
        );

        // Serialized BEFORE the delete — after it, soft-deleted or gone
        // attributes can no longer be trusted to read back the same.
        $data = $this->rowSerializer($published, $related)->serialize($childRecord);

        $childRecord->delete();

        return response()->json(['data' => $data]);
    }

    /**
     * The gates every relation endpoint shares, in the read endpoint's order:
     * resolution → class `viewAny` → relation discovery 404 → record
     * resolution → record `view` → the relation gate (which impersonates the
     * request's user on the panel's guard). A resource or relation nobody
     * serves is a 404 for everyone, and a 403 is never the answer for
     * something that does not exist.
     *
     * `$forWrite` adds the write-capability 404 immediately after discovery:
     * a relation whose child model resolves to zero or several mobile
     * resources has no form to write against, which is an unpublished
     * capability, not a denial — so all three write verbs 404 there, the
     * same answer /schema's absent `resource` key publishes. The value comes
     * from discovery (one resolution, one answer), never from a second
     * findByModel() at request time.
     *
     * @return array{0: class-string|null, 1: array<string, mixed>, 2: Model, 3: Relation, 4: Model, 5: MobileResource}
     */
    private function resolve(Request $request, string $resource, string $id, string $relation, bool $forWrite = false): array
    {
        // Resolution before authorization, as everywhere else: a resource
        // nobody serves is a 404 for everyone.
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // Gate 1 — the same gate show() applies, for the same reason:
        // Filament authorizes every resource page on canViewAny().
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        // A relation the schema refused is a 404, not a 403: it does not
        // exist as far as this API is concerned, and a 403 would suggest it
        // might appear for someone else.
        $published = collect(RelationDiscovery::for($class, $mobile, $this->registry))
            ->firstWhere('key', $relation)
            ?? abort(404, "No relation [{$relation}] on [{$resource}].");

        if ($forWrite && $published['resource'] === null) {
            abort(404, "Relation [{$relation}] on [{$resource}] has no single child resource, so its rows are read-only.");
        }

        $model = new ($class::getModel())();

        // Through the resource's query, so a record a global scope, a soft
        // delete or a tenant boundary hides is a genuine 404.
        $record = $class::getEloquentQuery()
            ->where($model->qualifyColumn($model->getRouteKeyName()), $id)
            ->first()
            ?? abort(404, "No [{$resource}] record [{$id}].");

        // The OTHER half of gate 1. `show()` gates on the class-level viewAny
        // above AND on this record's own `view`, and the spec's gate 1 is "the
        // parent must pass the existing show() authorization" — both checks,
        // not one. With only the first, an ownership policy that refuses
        // `GET /companies/1` still handed over `/companies/1/relations/banners`,
        // rows and all, and the 200 confirmed the row exists into the bargain.
        //
        // 403 rather than 404, matching show(): the resource's own query above
        // already answered the "this row is hidden" question.
        abort_unless(
            Authorizer::allowsRecord($request->user(), 'view', $record),
            403,
        );

        abort_unless(
            $this->relationGateAllows($published['manager'], $record, $request->user()),
            403,
        );

        // A `$relationship` naming no real Eloquent relation never reaches
        // here: `RelationDiscovery` builds it on the resource's model and
        // refuses the relation, so the 404 above already answered — and
        // `doctor` names the panel bug rather than leaving it as a 403 from
        // whichever gate happened to choke on it first.
        $relationship = $record->{$published['key']}();

        return [
            $forWrite ? $published['resource'] : null,
            $published,
            $record,
            $relationship,
            $relationship->getRelated(),
            // The read endpoint's per-relation search/sort declarations live
            // here (P11) — returned so ListQuery reads the same resolution
            // the gates already ran against.
            $mobile,
        ];
    }

    /**
     * The child row, resolved THROUGH the relationship by the related model's
     * own route key — a child of a different parent is a 404, never a
     * cross-parent write. Qualified, because a BelongsToMany's query carries
     * the pivot join and a bare `id` is ambiguous.
     */
    private function resolveChild(Relation $relationship, Model $related, string $child): Model
    {
        return $relationship
            ->where($related->qualifyColumn($related->getRouteKeyName()), $child)
            ->first()
            ?? abort(404, "No child [{$child}] on this relation.");
    }

    /**
     * Rows are serialized with the relation's published card and the related
     * model's route key — the relation envelope's row shape, identical to the
     * read endpoint's — and the child resource discovery resolved, so a
     * separator-configured column dehydrates the way the child's own resource
     * declares. `$published['resource']` is non-null on every write path (the
     * 404 in resolve() saw to that), which is what the child resource
     * argument needs.
     *
     * @param  array<string, mixed>  $published
     */
    private function rowSerializer(array $published, Model $related): RecordSerializer
    {
        return (new RecordSerializer(
            $published['card'],
            $related->getRouteKeyName(),
            $published['resource'],
            new RichContentAdapter(),
            new TagSeparatorAdapter(),
        ))->withMediaPaths($this->cardMediaPaths($published['resource'], $published['card']))
            ->withTagPaths($this->cardTagPaths($published['resource'], $published['card']));
    }

    /**
     * The child resource's own media paths, intersected with the relation's
     * published CARD — the same card-only whitelist `index()`'s
     * `cardMediaPaths()` enforces, for the same reason: a relation row is a
     * card row, never a detail screen, so a media field the card does not
     * show must not grow a `.__media` sibling here either.
     *
     * `$class` is nullable because the read endpoint's `$published['resource']`
     * is null whenever the child model resolves to zero or several mobile
     * resources (see `resolve()`) — a relation with no single child resource
     * publishes no media metadata, same as it publishes no write capability.
     *
     * @param  class-string|null  $class
     * @return array<string, array{collection: string, multiple: bool}>
     */
    private function cardMediaPaths(?string $class, MobileCard $card): array
    {
        if ($class === null) {
            return [];
        }

        $paths = [
            ...MediaFields::pathsIn($this->builder->schemaComponents($class, 'infolist')),
            ...MediaFields::pathsIn($this->builder->schemaComponents($class, 'form')),
        ];

        return array_intersect_key($paths, array_flip($card->fieldPaths()));
    }

    /**
     * The child resource's own tags paths, intersected with the relation's
     * published CARD — the tags read-path twin of `cardMediaPaths()` above,
     * for the same reason. Form-only: there is no infolist tags entry (see
     * `MobilePanelController::formProjection()`'s docblock).
     *
     * @param  class-string|null  $class
     * @return array<string, array{any: bool, type: ?string}>
     */
    private function cardTagPaths(?string $class, MobileCard $card): array
    {
        if ($class === null) {
            return [];
        }

        $paths = TagFields::pathsIn($this->builder->schemaComponents($class, 'form'));

        return array_intersect_key($paths, array_flip($card->fieldPaths()));
    }

    /**
     * Gate 2 — the panel's own answer for this owner record, asked about THIS
     * request's user.
     *
     * Filament's default implementation ends in `Filament\authorize()`, whose
     * first line is `Filament::auth()->user()` — the PANEL's guard. Nothing on
     * this route rewrites that guard: `auth:{guard}` middleware calls
     * `Auth::shouldUse()`, which moves the DEFAULT guard (so the bare `Gate`
     * facade does follow the request's user), but the panel keeps its own.
     * Left ambient, this gate answers about whoever happens to hold a panel
     * session, which on a token-authenticated request is a stranger — measured
     * both ways: a 403 for a user their own policy allows, and a 200 serving
     * rows to a caller because an unrelated admin's cookie rode along. The
     * second is privilege escalation, so the identity is established here
     * rather than inherited.
     *
     * `setUser()` is in-memory only — no session is written, nothing is
     * persisted — and it is undone in a `finally`, because a gate that throws
     * must not leave the request's user standing on the panel's guard.
     *
     * It does fire Filament's guard's `Authenticated` event, once or twice per
     * relation request. That is deliberate and left alone: suppressing it would
     * mean swapping the event dispatcher for the duration of the call, which
     * would also swallow whatever the HOST's own gate dispatches — a bigger
     * surprise than the event itself. Nothing logs in: no session is written
     * and no `Login` event fires, so a host seeing phantom `Authenticated`
     * events on the panel guard from a mobile request is seeing this line.
     *
     * The `$pageClass` argument is the manager's own class. This endpoint is
     * not a page: it is neither the resource's View page nor its Edit page,
     * and naming either would be a guess a panel is entitled to branch on. The
     * manager is what Filament itself passes when no page hosts it
     * (`CanAuthorizeAccess`: `$this->pageClass ?? static::class`), so an
     * override branching on a specific page class falls to its `else`, which
     * for the ordinary shape is a refusal — the safe direction.
     *
     * A gate that cannot answer refuses, and says so in the log: a deliberate
     * denial and a broken gate are the same 403 on the wire.
     *
     * @param  class-string  $manager
     */
    private function relationGateAllows(string $manager, Model $record, ?Authenticatable $user): bool
    {
        // Nobody to ask about is a gate that cannot answer, so it refuses —
        // stated here rather than left to the impersonation below, which
        // cannot enforce it: binding null only clears the guard's cache, and a
        // SessionGuard then re-reads the session and answers for a cookie
        // holder the request never authenticated as. Every route in
        // `routes.php` carries `auth`, so this is unreachable through the
        // package's own routing — but that is a property of the route file,
        // not of this controller, and it stops being true the first time
        // someone registers one of these controllers without that middleware.
        if ($user === null) {
            return false;
        }

        $guard = null;
        $previous = null;

        try {
            $guard = Filament::auth();
            $previous = $guard->user();
            self::actAs($guard, $user);

            return $manager::canViewForRecord($record, $manager);
        } catch (Throwable $e) {
            Log::warning('[filament-mobile] relation gate could not answer, refusing: ' . $e->getMessage());

            return false;
        } finally {
            if ($guard !== null) {
                self::actAs($guard, $previous);
            }
        }
    }

    /**
     * `forgetUser()` is not on the `Guard` contract, but every first-party
     * guard has it through `GuardHelpers`, and clearing is the common case:
     * a token-authenticated request usually leaves the panel's guard empty, so
     * restoring "nobody" is what the finally above normally has to do.
     */
    private static function actAs(Guard $guard, ?Authenticatable $user): void
    {
        if ($user !== null) {
            $guard->setUser($user);
        } elseif (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
    }
}
