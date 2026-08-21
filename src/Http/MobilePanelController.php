<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Filament\Actions\Action;
use Filament\Actions\Enums\ActionStatus;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Gait\FilamentMobile\Actions\ActionResolver;
use Gait\FilamentMobile\Introspection\ChildComponents;
use Gait\FilamentMobile\Introspection\ComponentTypeMap;
use Gait\FilamentMobile\Introspection\FieldPersistence;
use Gait\FilamentMobile\Introspection\FormDefaults;
use Gait\FilamentMobile\Introspection\MediaFields;
use Gait\FilamentMobile\Introspection\RichContent;
use Gait\FilamentMobile\Introspection\RichContentAdapter;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\TagFields;
use Gait\FilamentMobile\Introspection\TagSeparatorAdapter;
use Gait\FilamentMobile\Introspection\TagSeparators;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Write\RecordForm;
use Gait\FilamentMobile\Write\WritableNames;
use Gait\MobileCore\Authorizer;
use Gait\MobileCore\ListQuery;
use Gait\MobileCore\RecordSerializer;
use Gait\MobileCore\SettledSchema;
use Gait\MobileCore\WalkWarnings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class MobilePanelController
{
    public function __construct(
        private readonly PanelSchemaBuilder $builder,
        private readonly ResourceRegistry $registry,
    ) {
    }

    public function schema(Request $request): JsonResponse
    {
        $document = $this->builder->build($request->user());

        // Hashed BEFORE `_warnings` is attached, deliberately: warnings are
        // dev-only and not part of the contract, so folding them in would
        // move the ETag between environments and make every client
        // revalidate to a full 200 for a document that never changed.
        //
        // A content hash rather than anything user-derived: `/schema` is
        // filtered by policy, so two users legitimately see different
        // documents — and hashing what was actually built gets that right
        // without this endpoint knowing anything about identity.
        //
        // sha1, not xxh128: xxh128 is available on this PHP build, but its
        // determinism (not its speed) is what this endpoint needs, and sha1
        // is guaranteed present on every PHP without an extension check.
        $encoded = json_encode($document);

        // json_encode() returns false on failure (invalid UTF-8 in a
        // translated label is the realistic trigger), and (string) false is
        // ''. Hashing that silently would give every failing document the
        // same ETag, so two genuinely different documents could collide and
        // a client would keep a stale panel forever with no way to notice.
        // Fail loudly instead — the same posture SafeEvaluator's callers take
        // everywhere else a would-be silent corruption is worse than a 500.
        if ($encoded === false) {
            throw new RuntimeException('Could not encode the panel document to compute its ETag: ' . json_last_error_msg());
        }

        $etag = 'W/"' . sha1($encoded) . '"';

        if ($this->ifNoneMatchHits($request, $etag)) {
            // 304 carries no body by definition. Laravel's own
            // Response::prepare() (called for every controller response via
            // Router::runRoute()) sets the content to '' whenever the status
            // is 204/304 — verified against this project's installed
            // Illuminate\Http — so JsonResponse::json(null, 304) already
            // sends a genuinely empty body; no plain Response is needed.
            return response()->json(null, 304)->header('ETag', $etag);
        }

        // Read at request time, not cached at boot: the production test
        // flips the environment after the app has already booted.
        if (! app()->environment('production')) {
            $document['_warnings'] = $this->builder->warnings()->all();
        }

        return response()->json($document)->header('ETag', $etag);
    }

    /**
     * Whether the request's `If-None-Match` already names this document's
     * ETag. Tolerant of a comma-separated list and of either the weak
     * (`W/"…"`) or strong (`"…"`) form — a proxy may rewrite either — by
     * comparing the bare quoted value on both sides.
     */
    private function ifNoneMatchHits(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');

        if ($header === null) {
            return false;
        }

        $bare = $this->stripWeakPrefix($etag);

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            // RFC 7232 §3.2: `*` matches any current representation — the
            // "I have *a* cached copy, tell me if it's stale" form, distinct
            // from naming a specific ETag.
            if ($candidate === '*' || $this->stripWeakPrefix($candidate) === $bare) {
                return true;
            }
        }

        return false;
    }

    /** Strips a leading `W/` — the weak-validator marker — if present. */
    private function stripWeakPrefix(string $value): string
    {
        return str_starts_with($value, 'W/') ? substr($value, 2) : $value;
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        // Resolution before authorization, deliberately: a resource nobody
        // serves is a 404 for everyone, and a 403 is never the answer for
        // something that does not exist.
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // Filament's semantics, not a raw Gate::authorize(): a model with no
        // policy is visible on the phone exactly as it is on the web panel.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        $card = $mobile->getCard();

        // Computed before the query so its emptiness can gate the eager load
        // right below — an undotted media path (e.g. a `leadingImage('cover')`
        // slot) never appears in `relationPaths()`, which only derives from
        // DOTTED card fields, so without this a card-bound `cover` lazy-loads
        // `media` once per row: 20 rows, 20 extra queries.
        $cardMediaPaths = $this->cardMediaPaths($class, $card);

        // Same reasoning as $cardMediaPaths, and the same eager-load gap it
        // closes: a card-bound tags field (e.g. a `subtitle('tags')`) is
        // read off the model's `tags`/`tagsWithType()` relation, which
        // lazy-loads once per row without an explicit eager load.
        $cardTagPaths = $this->cardTagPaths($class, $card);

        // The resource's own query, never the model's: global scopes, soft
        // deletes and tenancy are inherited rather than reimplemented here.
        $query = $class::getEloquentQuery()
            // Derived from the card's dotted paths, so the N+1 defence needs
            // no declaration and cannot drift from what is serialised.
            ->with($card->relationPaths());

        // `cardMediaPaths()`/`cardTagPaths()` are schema-only (no model
        // check — see their docblocks), so a card-bound path survives on a
        // model that never registered HasMedia/HasTags. Eager-loading a
        // relation the model does not declare is a RelationNotFoundException,
        // not a graceful empty read — RecordSerializer's own `method_exists`
        // gate only protects the READ, not this eager load, so the trait
        // check has to happen here too.
        if ($cardMediaPaths !== [] && method_exists($class::getModel(), 'getMedia')) {
            $query->with('media');
        }

        if ($cardTagPaths !== [] && method_exists($class::getModel(), 'syncTagsWithType')) {
            $query->with('tags');
        }

        ListQuery::applySearch($query, ListQuery::stringQuery($request, 'search'), $mobile->getSearchable());
        ListQuery::applySort($query, $request, $mobile->getSorts(), $mobile->getDefaultSortKey(), $mobile->getDefaultSortDirection());

        $records = $query->paginate(config('filament-mobile.per_page'));

        $serializer = (new RecordSerializer(
            $card,
            (new ($class::getModel())())->getRouteKeyName(),
            $class,
            new RichContentAdapter(),
            new TagSeparatorAdapter(),
        ))
            ->withMediaPaths($cardMediaPaths)
            ->withTagPaths($cardTagPaths);

        return response()->json([
            'data' => array_map(
                static fn (Model $record): array => $serializer->serialize($record),
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

    public function store(Request $request, string $resource): JsonResponse
    {
        // Same order as index()/show(), for the same reason: a resource
        // nobody serves is a 404 for everyone, and a 403 is never the answer
        // for something that does not exist.
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // Class-level, not per-record: there is no record yet. See
        // Authorizer::allows() vs allowsRecord().
        abort_unless(
            Authorizer::allows($request->user(), 'create', $class::getModel()),
            403,
        );

        // The validated array is also the mass-assignment whitelist: only a
        // key the form declares a rule for survives $request->validate(), so
        // an undeclared field (e.g. `internal_note`) never reaches create().
        //
        // Built through SettledSchema, not from the payload: a gate reading a
        // sibling the form will never write (`Hidden`, unmapped, `file`) would
        // otherwise be opened by crafting that sibling's value. See
        // SettledSchema and WritableNames.
        $settled = SettledSchema::settle(
            submitted: $request->all(),
            // The panel's own defaults are the trusted state on create: there
            // is no record yet, and FormDefaults computes exactly what
            // Filament's CreateRecord would fill.
            //
            // From an EMPTY state, never the submitted one, and that is the
            // whole guard. `trusted` is not merely a reset target: reset()
            // seeds EVERY pass's state from it and it is never re-derived, so
            // anything landing in it is a permanent floor the final build
            // reads — and row defaults come off that build. Deriving it from
            // the payload therefore let a crafted value open a `visible` gate,
            // plant that component's default in `trusted`, and have it open a
            // second gate on every later pass. Two hops, and a column the
            // panel would never have written gets one. See `lever` /
            // `victim_note` in BannerResource.
            //
            // An empty state is also exactly what Filament's own CreateRecord
            // fills defaults into, so this is the closer match either way.
            trusted: FormDefaults::fromComponents(
                RecordForm::components($class, [], record: null),
            ),
            build: function (array $state) use ($class): array {
                $components = RecordForm::components($class, $state, record: null);

                return ['components' => $components, 'writable' => WritableNames::of($components)];
            },
        );

        $components = $settled->components();

        $validated = $request->validate(
            RecordForm::rules($settled),
            attributes: RecordForm::validationAttributes($settled),
        );

        $model = $class::getModel();

        // Under the payload, never over it: the form's `->default()` values are
        // what Filament's own CreateRecord fills before saving, so a mobile
        // create that ignores them writes a row the web panel would not have.
        // A `Hidden::make('type')->default(...)` — the ordinary way a resource
        // stamps a record as its own — is invisible to the client by
        // definition, so only the server can supply it. See FormDefaults.
        // TagSeparators::dehydrate() is the package's one reproduction of
        // Filament's dehydration, and it wraps the FINAL attribute array at
        // BOTH write seams — see update(), which calls the same helper for the
        // same reason. A separator changes the column's shape, so a mirror
        // applied to one seam is a bug in the other.
        $record = $model::create(TagSeparators::dehydrate(
            RecordForm::fillMissingPaths($validated, FormDefaults::fromComponents($components)),
            $components,
        ));

        RecordForm::saveRelations($class, $settled->state(), $record, 'create', $request->all());

        $serializer = (new RecordSerializer(
            $mobile->getCard(),
            $record->getRouteKeyName(),
            $class,
            new RichContentAdapter(),
            new TagSeparatorAdapter(),
        ))
            ->withMediaPaths($this->mediaPathsFor($class))
            ->withTagPaths($this->tagPathsFor($class));

        return response()->json(['data' => $serializer->serialize($record)], 201);
    }

    public function show(Request $request, string $resource, string $id): JsonResponse
    {
        // Same order as index(), for the same reason: a resource nobody serves
        // is a 404 for everyone, and a 403 is never the answer for something
        // that does not exist.
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // The same gate index() and /schema apply, and for the same reason:
        // Filament authorizes *every* resource page — ViewRecord included —
        // on Resource::canAccess(), which is canViewAny(). Gating the detail
        // endpoint on the record's `view` alone makes mobile looser than the
        // web panel wherever `view()` does not delegate to `viewAny()`, which
        // is the ordinary shape of an ownership policy.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        $model = new ($class::getModel())();

        // Through the resource's query, so a record a global scope, a soft
        // delete or a tenant boundary hides is a genuine 404 — never a 403,
        // which would confirm the row exists.
        // ponytail: no ->with(). Eager loading is the N+1 defence for a *page*
        // of records; for one record it trades a lazy load for an identical
        // extra query and buys nothing.
        $record = $class::getEloquentQuery()
            ->where($model->qualifyColumn($model->getRouteKeyName()), $id)
            ->first()
            ?? abort(404, "No [{$resource}] record [{$id}].");

        $user = $request->user();

        // Against the loaded record, never the class: this is the per-record
        // half of the permissions contract. See Authorizer::allowsRecord().
        $mayView = Authorizer::allowsRecord($user, 'view', $record);

        abort_unless($mayView, 403);

        $attributes = $record->attributesToArray();

        // Two path sets, deliberately not merged. An infolist entry named
        // `caption` and a form field named `caption.ar` are different things
        // sharing one JSON key: nesting the second replaces the first, and the
        // endpoint answered `{"caption": {"ar": null}}` for exactly that
        // reason. The infolist keeps its nesting; the form gets literal keys.
        //
        // Walked once and read twice: the leaf names are what gets serialised,
        // and the `rich_entry` names are the schema's half of the rich-path
        // union (the model's half is the serializer's own — see
        // RecordSerializer::richPathsFor(), which is where the union lives).
        // A `->prose()` entry over a column the model declares nothing about
        // is covered only by this half.
        $infolist = $this->infolistNodes($class, $resource, $attributes, $infolistComponents);

        $form = $this->formProjection($class, $resource, $attributes, $record);

        // The form's own media uploads plus the infolist's read-only media
        // entries (`SpatieMediaLibraryImageEntry`) — a card's leading image
        // and a detail-screen image both name a path that may never appear
        // in the form at all. The form's declaration wins on a name both
        // sides use for the same field (GalleryResource's `cover` is both),
        // which is moot in practice: the same collection reads the same way
        // from either component.
        $form['mediaPaths'] = [...MediaFields::pathsIn($infolistComponents), ...$form['mediaPaths']];

        $serializer = (new RecordSerializer(
            $mobile->getCard(),
            $model->getRouteKeyName(),
            $class,
            new RichContentAdapter(),
            new TagSeparatorAdapter(),
        ))
            ->withInfolistPaths($this->leafNames($infolist))
            ->withFormPaths($form['paths'])
            ->withRepeaterRows($form['repeaterRows'])
            ->withRichPaths(RichContent::entryNamesIn($infolist))
            ->withMediaPaths($form['mediaPaths'])
            ->withTagPaths($form['tagPaths']);

        $resolver = new ActionResolver($class, $mobile);

        return response()->json([
            // Not wrapped in a hydrate call here, deliberately: the read half
            // lives inside RecordSerializer, so index() and the
            // write responses get it too. Wrapping one seam is how the first
            // cut of this shipped two shapes for one column.
            'data' => $serializer->serialize($record),
            // The resource-level block in /schema reports capability, because
            // an ownership policy has no class-level answer. This one is the
            // truth for this row, and the two are free to disagree.
            'permissions' => [
                'view' => $mayView,
                'update' => Authorizer::allowsRecord($user, 'update', $record),
                'delete' => Authorizer::allowsRecord($user, 'delete', $record),
            ],
            // Per-record, like `permissions` and for the same reason: an
            // action's visibility and authorization are facts about THIS row.
            // Absent means unavailable — this package does not publish dead
            // controls. Always present, `[]` when the resource opted none in.
            'actions' => array_values(array_map(
                fn ($action) => $resolver->serialise($action),
                $resolver->available($record),
            )),
        ]);
    }

    /**
     * Run one opted-in, form-free record action — what a tap on the web
     * panel's row button does, minus the modal. A known, documented delta,
     * like destroy()'s DeleteAction one: this is the action's bare `call()`,
     * so its `before()`/`after()` hooks (`callBefore()`/`callAfter()`) and
     * any declared `->databaseTransaction()` do not run here — those live in
     * Filament's Livewire mounting flow, which this package deliberately
     * does not host. Everything inside the action closure itself does run,
     * and the resolver has already re-answered visibility and authorization.
     * An action relying on `before()` for a side effect the mobile tap must
     * also produce should move that work into the closure or an observer,
     * which both panels then honour.
     *
     * Resolution order is every other endpoint's, show() included: resource
     * 404 → viewAny 403 → record 404 → action gate 403. The page-level gate
     * runs BEFORE the record lookup, not after: a caller with no access to
     * the resource type at all must get the same 403 for a real id and a
     * nonexistent one, or the status code itself becomes an oracle for which
     * ids exist on a resource the caller cannot see — exactly the enumeration
     * leak show()'s own ordering exists to close.
     *
     * The action gate proper is `ActionResolver::resolve()`, re-answered here
     * against the record as it stands NOW, because the payload that listed
     * this action was a hint and may be stale or crafted. Every refusal past
     * this point is the same 403: distinguishing "not opted in" from "hidden
     * for this row" would leak the panel's configuration to a client that
     * guessed a name.
     *
     * The response carries no record body. An action's most common effect is
     * changing exactly the permissions and actions the client is holding, so
     * the client re-fetches the record and gets all three fresh together.
     */
    public function runAction(Request $request, string $resource, string $id, string $action): JsonResponse
    {
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // Before the record lookup, as show() applies it: an action is
        // reachable only on a resource the user may reach at all, and asking
        // this before the record exists is what keeps a real id and a fake
        // one answering identically.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        $model = new ($class::getModel())();

        $record = $class::getEloquentQuery()
            ->where($model->qualifyColumn($model->getRouteKeyName()), $id)
            ->first()
            ?? abort(404, "No [{$resource}] record [{$id}].");

        $resolved = (new ActionResolver($class, $mobile))->resolve($action, $record)
            ?? abort(403);

        try {
            $resolved->call();
        } catch (Halt) {
            // Filament's own "stop, and tell the user why" path. A halt is a
            // refusal the action chose, not a server fault: 422, with the
            // action's own failure title when it declared one.
            return response()->json(
                ['message' => $this->notificationTitle($resolved, 'getFailureNotificationTitle')],
                422,
            );
        } catch (Cancel) {
            // The web panel's other graceful exit: it catches Cancel and
            // sends NO notification at all — a no-op the action chose, not a
            // fault and not a refusal. 200 with no message, matching that
            // silence; the client re-fetches and moves on.
            return response()->json(['message' => null]);
        }

        // The call returned normally, but that is only half of Filament's
        // answer: the web panel switches on getStatus() after the call, and
        // a closure that ran `$this->failure()` gets the FAILURE
        // notification there. The same 422-with-failure-title as a Halt —
        // and like a Halt's, the closure's side effects up to the failure()
        // are real and stand; the status is a report, not a rollback.
        if ($resolved->getStatus() === ActionStatus::Failure) {
            return response()->json(
                ['message' => $this->notificationTitle($resolved, 'getFailureNotificationTitle')],
                422,
            );
        }

        // The action has already run by this point — its side effect is
        // real regardless of what happens next. A throwing title is
        // therefore cosmetic, not a reason to report the request as failed:
        // that would tell the client an action succeeded, mutated the
        // record, and then have the client believe it didn't and possibly
        // retry it. Degrades to null, same as ActionResolver::serialise()'s
        // label/color/icon getters; the client falls back to its own
        // generic string.
        return response()->json(['message' => $this->notificationTitle($resolved, 'getSuccessNotificationTitle')]);
    }

    /**
     * `getSuccessNotificationTitle()` / `getFailureNotificationTitle()` can
     * be closures, and by the time either is read the action has already
     * run — its side effect is real regardless of what this getter does.
     * A throwing title is therefore cosmetic, not an action failure:
     * reporting one as a 500 would tell a client whose mutation genuinely
     * went through (or genuinely halted) that the request failed, inviting
     * a retry of an action that already ran once. Degrades to `null`, the
     * same ruling `ActionResolver::serialise()` already applies to a
     * throwing label/color/icon — the client falls back to its own generic
     * string.
     *
     * @param  'getSuccessNotificationTitle'|'getFailureNotificationTitle'  $getter
     */
    private function notificationTitle(Action $action, string $getter): ?string
    {
        try {
            return $action->{$getter}();
        } catch (Throwable) {
            return null;
        }
    }

    public function update(Request $request, string $resource, string $id): JsonResponse
    {
        // Same order as show() and runAction(): resource 404 → viewAny 403 →
        // record 404 → record gate 403 — a resource or record nobody serves
        // must never leak its existence through a 403.
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // BEFORE the record lookup, for the same two reasons show() gives:
        // Filament gates every resource page — Edit included — on
        // canAccess(), i.e. viewAny; and a caller denied at the resource
        // level must get the same 403 for a real id and a fake one, or the
        // status code becomes an oracle for which ids exist.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        $model = new ($class::getModel())();

        // Through the resource's query, so a record a global scope, a soft
        // delete or a tenant boundary hides is a genuine 404 — never a 403,
        // which would confirm the row exists.
        $record = $class::getEloquentQuery()
            ->where($model->qualifyColumn($model->getRouteKeyName()), $id)
            ->first()
            ?? abort(404, "No [{$resource}] record [{$id}].");

        // Against the loaded record, never the class: update is authorization,
        // not capability. An ownership policy's class-level answer is `true`
        // for every record — see Authorizer::allows() vs allowsRecord().
        abort_unless(
            Authorizer::allowsRecord($request->user(), 'update', $record),
            403,
        );

        // The validated array is also the mass-assignment whitelist: only a
        // key the form declares a rule for survives $request->validate(), so
        // an undeclared field never reaches update().
        //
        // The record's stored values sit under the payload: a partial PUT that
        // does not resend `country_id` must still resolve a field whose
        // visibility depends on it, or the field loses its rules and its
        // submitted value is silently dropped.
        //
        // `attributesToArray()`, not `getAttributes()`: Filament's own
        // EditRecord fills the form from the cast values, so a JSON, enum or
        // date column must drive a closure here exactly as it does on the web
        // panel. Raw column values are a divergence this package exists to
        // avoid.
        // Through SettledSchema, for the reason store() is: a gate reading a
        // sibling this form will never write is a gate the payload can open.
        $settled = SettledSchema::settle(
            submitted: [...$record->attributesToArray(), ...$request->all()],
            trusted: $record->attributesToArray(),
            // The record, not just its attributes: state alone answers
            // `Get $get`, but `disabled(fn (Model $record) => ...)` and
            // `disabledOn('edit')` need the schema to know which row this is
            // and that this is an edit. Without it every such gate evaluated
            // as a create, i.e. open.
            build: function (array $state) use ($class, $record): array {
                $components = RecordForm::components($class, $state, $record);

                return ['components' => $components, 'writable' => WritableNames::of($components)];
            },
        );

        $rules = RecordForm::rules($settled);

        $validated = $request->validate(
            $rules,
            attributes: RecordForm::validationAttributes($settled),
        );

        // A dotted rule path (`caption.en`) validates into a nested array, and
        // Eloquent writes the WHOLE `caption` attribute — so a PUT carrying one
        // locale erases every sibling the payload did not mention. That is not
        // a translation edge case: any locale the form marks Hidden, disabled,
        // disabledOn('edit') or dehydration-refused is absent from every
        // payload by construction, so its stored value was lost on every save.
        // The record's own value fills the gaps, path by path — same helper the
        // defaults use on create, so the two can't drift.
        // The same one transform store() applies, at the second write seam —
        // see TagSeparators and store()'s comment.
        $record->update(TagSeparators::dehydrate(
            RecordForm::fillMissingPaths($validated, RecordForm::storedPaths($record, array_keys($rules))),
            $settled->components(),
        ));

        RecordForm::saveRelations($class, $settled->state(), $record, 'edit', $request->all());

        $serializer = (new RecordSerializer(
            $mobile->getCard(),
            $record->getRouteKeyName(),
            $class,
            new RichContentAdapter(),
            new TagSeparatorAdapter(),
        ))
            ->withMediaPaths($this->mediaPathsFor($class))
            ->withTagPaths($this->tagPathsFor($class));

        return response()->json(['data' => $serializer->serialize($record)]);
    }

    public function destroy(Request $request, string $resource, string $id): JsonResponse
    {
        // Same order as show()/update(): resource 404 → viewAny 403 → record
        // 404 → record gate 403 — a resource or record nobody serves must
        // never leak its existence through a 403.
        [$class, ] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // BEFORE the record lookup — see update() for both reasons: web
        // fidelity (every Filament page gates on viewAny) and the
        // enumeration guard (identical 403 for a real and a fake id).
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        $model = new ($class::getModel())();

        // Through the resource's query, so a record a global scope, a soft
        // delete or a tenant boundary hides is a genuine 404 — never a 403,
        // which would confirm the row exists.
        $record = $class::getEloquentQuery()
            ->where($model->qualifyColumn($model->getRouteKeyName()), $id)
            ->first()
            ?? abort(404, "No [{$resource}] record [{$id}].");

        // Against the loaded record, never the class: delete is
        // authorization, not capability. An ownership policy's class-level
        // answer is `true` for every record — see Authorizer::allows() vs
        // allowsRecord().
        abort_unless(
            Authorizer::allowsRecord($request->user(), 'delete', $record),
            403,
        );

        // The model's delete, NOT Filament's DeleteAction — a known delta, so
        // it is documented rather than silently different. `DeleteAction` is a
        // Livewire construct this package deliberately does not host, so its
        // `before()`/`after()` hooks and its restrict-if-related-records guard
        // do not run here. Everything hanging off `$record->delete()` does:
        // observers, soft deletes, cascades, and the policy already checked
        // above. The pilot measured 1 of 33 resources relying on a hook (it
        // retires a WhatsApp template in Meta, which a mobile delete leaves
        // live) — the fix for such a resource is an observer, which both
        // panels then honour.
        $record->delete();

        return response()->json(null, 204);
    }

    /**
     * The resource's infolist as the walker sees it — the same walk /schema
     * publishes, so the detail payload cannot drift from the detail screen the
     * client was told to render.
     *
     * The TREE, not the flattened names, because show() needs two answers from
     * one walk: which paths to serialise (`leafNames()`, where layout nodes
     * name a section rather than a column) and which of them are rich
     * (`richNames()`). Walking twice would be the same rule in two places.
     *
     * Built through the same host the write path uses, seeded with this
     * record's cast values: an infolist entry may be conditional too, and a
     * null host fatals on the first one — silently, because the catch below
     * turns it into a fallback to the card's fields.
     *
     * `$rawComponents` is an out-parameter carrying the raw (unwalked)
     * component list back to the caller — `show()` needs it to fold this
     * infolist's own media entries (`SpatieMediaLibraryImageEntry`) into the
     * record's `mediaPaths` union alongside the form's, and a walked NODE
     * carries no collection name (that is deliberately a server-side
     * concern, never published — see MediaFields). Set to `[]` on the
     * construction-time failure below, same as the return value.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @param  list<object>|null  $rawComponents
     * @return list<array<string, mixed>>
     */
    private function infolistNodes(string $class, string $resourceKey, array $state, ?array &$rawComponents = null): array
    {
        $walker = new SchemaWalker(new WalkWarnings());
        $rawComponents = [];

        try {
            // `operation: 'view'`, and deliberately NO record, even though one
            // is in hand two lines up in show().
            //
            // Passing it would resolve `infolist(fn (Order $record) => ...)`,
            // which is the exact construction hazard the catch below exists to
            // survive — and BrokenSchemaTest's last case is the only test of
            // the silent-fallback-plus-log path. Handing the record over here
            // would make that fixture construct cleanly and delete the coverage
            // without a single test turning red. The record-typed closure stays
            // unresolvable on this path on purpose.
            //
            // The cost is bounded and read-only: infolistPaths() decides which
            // columns get serialised, never what gets written, and it already
            // falls back to the card's fields. Every write path does get the
            // record — see RecordForm::components().
            $components = $class::infolist(RecordForm::schema($class, $state, 'view'))
                ->getComponents();

            $rawComponents = $components;
        } catch (Throwable $e) {
            // Same construction-time hazard /schema guards against: a closure
            // typed against the record fatals when getComponents() evaluates it
            // without one. The record still serialises — it falls back to the
            // card's fields, which is a thinner detail screen rather than a 500.
            //
            // The response has nowhere to carry a warning, but degrading a
            // detail screen without recording it anywhere is how this becomes
            // a bug report nobody can reproduce. /schema names the resource in
            // its warnings; this leaves the same fact in the log.
            Log::warning('[filament-mobile] could not build the infolist schema for '
                . class_basename($class) . ', falling back to card fields only: '
                . $e->getMessage());

            return [];
        }

        return $walker->walk($components, class_basename($class), $resourceKey, $class::getModel());
    }

    /**
     * The form's leaf field names, for serialising a record the edit screen
     * will prefill.
     *
     * Unlike infolistPaths(), this DOES pass the record: RecordForm::components()
     * already does so on every write path, and an edit form that resolves its
     * gates as a create is the defect Task 6 of P2-Laravel closed. The
     * try/catch mirrors infolistPaths(): a throwing form costs the prefill,
     * not the whole detail screen.
     *
     * `mediaPaths` is this FORM's half of the record's media union (Task 3's
     * `RecordSerializer::withMediaPaths()`) — leaf name => collection +
     * multiplicity for every Spatie upload the form declares. `show()` folds
     * in the infolist's own media entries beside it, the same two-halves
     * shape `RichContent::entryNamesIn()`/`attributesFor()` already follow
     * for rich content.
     *
     * `tagPaths` is this FORM's half of the record's tags union — there is no
     * infolist counterpart (the plugin has no read-only tags entry, unlike
     * media's `SpatieMediaLibraryImageEntry`), so unlike `mediaPaths` this is
     * never folded with an infolist half in show().
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return array{paths: list<string>, repeaterRows: array<string, list<array<string, mixed>>>, mediaPaths: array<string, array{collection: string, multiple: bool}>, tagPaths: array<string, array{any: bool, type: ?string}>}
     */
    private function formProjection(string $class, string $resourceKey, array $state, Model $record): array
    {
        $walker = new SchemaWalker(new WalkWarnings());

        try {
            $components = RecordForm::components($class, $state, $record);
        } catch (Throwable $e) {
            Log::warning('[filament-mobile] could not build the form schema for '
                . class_basename($class) . ', the edit form will open unprefilled: '
                . $e->getMessage());

            return ['paths' => [], 'repeaterRows' => [], 'mediaPaths' => [], 'tagPaths' => []];
        }

        // $model matters here for the exact reason infolistPaths() passes it
        // at :728: a Spatie tags field on a model without HasTags fails
        // closed only when the walker can ask the model, and without it a
        // traitless model's field survives in `paths` — which the generic
        // form-field pass below then reads straight off the record, resolving
        // whatever real relation the name happens to collide with.
        $nodes = $walker->walk($components, class_basename($class), $resourceKey, $class::getModel());

        // A RELATIONSHIP repeater is the one repeater leafNames() must not
        // keep: it has no column of its own — its rows are child records the
        // relation pass writes (P9), never an attribute on this model — so
        // collecting its name would have RecordSerializer read a non-existent
        // attribute, and for the common idiom that names the field after the
        // relationship (`Repeater::make('tags')->relationship()`) data_get()
        // would resolve the RELATION and publish whole child models, well past
        // the card's whitelist.
        //
        // It is identified off the COMPONENTS, not the walked node. Before P9
        // the node itself carried the tell (`config.readOnly` and
        // `writable: false` together); a relationship repeater is writable
        // now, so its node is indistinguishable from a JSON-column
        // repeater's, and the answer has to come from the component tree.
        //
        // Subtracted from the ordinary path pass and then published by a pass
        // of its own — PROJECTED onto the item template's declared child
        // fields, which is the distinction that makes it publishable at all.
        // Leaving it merely absent is what shipped the data loss this pass
        // exists to close: the client seeded the writable field to null,
        // `payloadFor()` sent that null, and `Arr::has()` read a present null
        // as a deliberate clear, so saving an unrelated column deleted every
        // child row. See RepeaterWriteTest's null case.
        $repeaters = $this->relationshipRepeaters($components);

        return [
            'paths' => array_values(array_diff(
                $this->leafNames($nodes),
                array_keys($repeaters),
            )),
            'repeaterRows' => $this->repeaterRelationRows(
                $repeaters,
                $this->repeaterChildNames($nodes),
                $record,
            ),
            'mediaPaths' => MediaFields::pathsIn($components),
            'tagPaths' => TagFields::pathsIn($components),
        ];
    }

    /**
     * The resource's full media union — form uploads plus infolist entries —
     * for a write response, which the client typically re-renders as the
     * detail screen it just created or edited. A thinner set here would have
     * the store/update response disagree with an immediate GET of the same
     * record.
     *
     * Schema-only, like `cardMediaPaths()`: a media path's shape (collection
     * name, multiplicity) is a fact about the SCHEMA, not this one record, so
     * `PanelSchemaBuilder::schemaComponents()` answers it without the record-
     * bound `infolistNodes()`/`formProjection()` walk — the walk's own return
     * value went unused here, and `formProjection()`'s relationship-repeater
     * half ran a real query per repeater to build rows nothing in this
     * response reads. `store()`/`update()` never queried like that before
     * this method existed, and nothing downstream needs the walked nodes —
     * only the raw components `MediaFields::pathsIn()` reads.
     *
     * @param  class-string  $class
     * @return array<string, array{collection: string, multiple: bool}>
     */
    private function mediaPathsFor(string $class): array
    {
        return [
            ...MediaFields::pathsIn($this->builder->schemaComponents($class, 'infolist')),
            ...MediaFields::pathsIn($this->builder->schemaComponents($class, 'form')),
        ];
    }

    /**
     * The resource's full tags union, for a write response — the same
     * "schema-only" precedent `mediaPathsFor()` sets, and the reason it does:
     * a write response typically becomes the detail screen the client just
     * created or edited, and a thinner set here would have it disagree with
     * an immediate GET of the same record.
     *
     * Form-only, unlike `mediaPathsFor()`: there is no infolist tags entry to
     * fold in (see `formProjection()`'s docblock).
     *
     * @param  class-string  $class
     * @return array<string, array{any: bool, type: ?string}>
     */
    private function tagPathsFor(string $class): array
    {
        return TagFields::pathsIn($this->builder->schemaComponents($class, 'form'));
    }

    /**
     * The resource's OWN media paths, intersected with the CARD's whitelist —
     * `index()`'s card-only serializer must not grow a `.__media` sibling for
     * a field the list row never carries in the first place (`photos` on
     * GalleryResource: a form-only upload, absent from the card).
     *
     * Built off the schema alone (`PanelSchemaBuilder::schemaComponents()`,
     * the same record-less build `/schema` itself uses), because `index()`
     * serialises a whole PAGE of records through one serializer — a media
     * path's shape (collection name, multiplicity) is a fact about the
     * schema, never about any one row.
     *
     * @param  class-string  $class
     * @return array<string, array{collection: string, multiple: bool}>
     */
    private function cardMediaPaths(string $class, MobileCard $card): array
    {
        $paths = [
            ...MediaFields::pathsIn($this->builder->schemaComponents($class, 'infolist')),
            ...MediaFields::pathsIn($this->builder->schemaComponents($class, 'form')),
        ];

        return array_intersect_key($paths, array_flip($card->fieldPaths()));
    }

    /**
     * The resource's OWN tags paths, intersected with the CARD's whitelist —
     * the same reasoning `cardMediaPaths()` gives, and form-only for the same
     * reason `tagPathsFor()` is: there is no infolist tags entry.
     *
     * @param  class-string  $class
     * @return array<string, array{any: bool, type: ?string}>
     */
    private function cardTagPaths(string $class, MobileCard $card): array
    {
        $paths = TagFields::pathsIn($this->builder->schemaComponents($class, 'form'));

        return array_intersect_key($paths, array_flip($card->fieldPaths()));
    }

    /**
     * Each relationship repeater's rows, as the wire shape the client's
     * repeater field seeds from and hands straight back: a list of maps, one
     * key per field the item template declares.
     *
     * The projection is the whole point. `$record->tags` is a collection of
     * whole child models — timestamps, foreign keys, anything else on the
     * table — and publishing that would put columns no screen declared onto
     * the wire. Only the template's own leaf names travel, the same whitelist
     * discipline the card and infolist passes follow.
     *
     * Row identity deliberately does not travel: the save is
     * delete-all-then-recreate (keyless state, pinned in RepeaterWriteTest),
     * so a child's id would be a value the client cannot round-trip
     * meaningfully. What it round-trips is content.
     *
     * ponytail: rows arrive in the relation's own order, so a repeater
     * declaring `->orderColumn()` is served by that column only if the
     * relationship already sorts on it. Read the order column through the
     * component if a panel shows it mattering.
     *
     * @param  array<string, object>  $repeaters
     * @param  array<string, list<string>>  $childNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function repeaterRelationRows(array $repeaters, array $childNames, Model $record): array
    {
        $rows = [];

        foreach ($repeaters as $name => $component) {
            $fields = $childNames[$name] ?? [];

            if ($fields === []) {
                continue;
            }

            try {
                $relation = $component->getRelationship();

                if ($relation === null) {
                    continue;
                }

                $children = $relation->get();
            } catch (Throwable $e) {
                // The same degradation every other read here takes: this one
                // field opens empty rather than the detail screen failing.
                // Absent, not `[]` — an empty list is a real answer meaning
                // "no rows", and a client that cannot tell them apart would
                // submit a deliberate clear it never asked for.
                Log::warning('[filament-mobile] could not read relationship repeater `'
                    . $name . '` on ' . class_basename($record::class) . ': ' . $e->getMessage());

                continue;
            }

            $rows[$name] = array_values(array_map(
                function (Model $child) use ($fields): array {
                    $row = [];

                    foreach ($fields as $field) {
                        $row[$field] = data_get($child, $field);
                    }

                    return $row;
                },
                $children->all(),
            ));
        }

        return $rows;
    }

    /**
     * Every repeater node's item-template leaf names, keyed by the repeater's
     * own name — read off the WALKED nodes rather than re-descending the
     * components, because the walk already published the template once as
     * `children` and its leafNames() answer is the one the client renders
     * against.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, list<string>>
     */
    private function repeaterChildNames(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (! is_array($node['children'] ?? null)) {
                continue;
            }

            if (($node['type'] ?? null) === 'repeater') {
                if (is_string($node['name'] ?? null)) {
                    $names[$node['name']] = $this->leafNames($node['children']);
                }

                continue;
            }

            $names = [...$names, ...$this->repeaterChildNames($node['children'])];
        }

        return $names;
    }

    /**
     * Every relationship repeater in a form tree, keyed by its name — for
     * formProjection() above, which needs both the names (to subtract from the
     * ordinary path pass) and the components (to read their relationships).
     * One traversal serving both is why this returns the map rather than the
     * list it used to.
     *
     * Disabled or not: `savesViaRelationship()` is a fact about the
     * component's shape, not its gates, so a disabled relationship repeater —
     * refused by the write path — is collected here too. Its rows are then
     * published like any other's, which is correct: the field renders
     * read-only from its own `disabled` flag, and a client that cannot see
     * the rows is exactly what caused them to be destroyed.
     *
     * Layout containers are recursed; a repeater's item template is not — its
     * children are per-item paths (`items.*.field`), never top-level names,
     * so a nested relationship repeater can never reach the projection anyway.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, object>
     */
    private function relationshipRepeaters(iterable $components): array
    {
        $names = [];

        foreach ($components as $component) {
            if (! is_object($component) || ComponentTypeMap::isSkipped($component)) {
                continue;
            }

            $type = ComponentTypeMap::for($component);

            if ($type === null) {
                continue;
            }

            if ($type === 'repeater') {
                if (FieldPersistence::savesViaRelationship($component)) {
                    try {
                        $name = $component->getName();
                    } catch (Throwable) {
                        continue;
                    }

                    if (is_string($name) && $name !== '') {
                        $names[$name] = $component;
                    }
                }

                continue;
            }

            if (in_array($type, ComponentTypeMap::LAYOUT_TYPES, true)) {
                $names = [...$names, ...$this->relationshipRepeaters(ChildComponents::of($component))];
            }
        }

        return $names;
    }

    /**
     * A repeater node carries BOTH `children` (its item template, published
     * once — see SchemaWalker) AND its own writable name: the whole array is
     * one attribute on the model (design spec, "two different name spaces").
     * Every other node with `children` — `section`, `grid`, `tabs`,
     * `fieldset` — is a pass-through container with no column of its own, so
     * recursing without collecting its name is still correct for those.
     *
     * Keyed on `type === 'repeater'` rather than "has children and a name",
     * matching the idiom RuleExtractor and DoctorCommand already use to spot
     * a repeater node: a `section`'s own `name` is null (SchemaWalkerTreeTest
     * asserts this directly; `grid`/`tabs`/`fieldset` are argued the same way
     * from source — HasLabel/HasHeading own the constructor argument, not a
     * state path — but are not themselves pinned by a null-name assertion),
     * so today the two conditions would likely pick out the same nodes — but
     * keying on the type says what's actually being special-cased, and does
     * not depend on that holding for every layout type.
     *
     * A repeater's children are per-item paths (`items.*.field`), never
     * top-level names, so they must NOT be recursed into here — doing so
     * would hand the serializer a path like `sku` that doesn't exist as a
     * column on the parent model. That was the pre-fix behaviour: a
     * repeater used to fall into the generic `isset($node['children'])`
     * branch below and recurse, so its item template's field names leaked in
     * as bogus top-level paths and RecordSerializer read `$record->sku` —
     * nonexistent, silently null — into every payload. Returning before that
     * recursion closes this leak as a side effect of the fix, not just the
     * missing-key defect the tests below name.
     *
     * Every repeater's own name IS collected here, relationship repeaters
     * included: distinguishing those is a component-level question (P9 made
     * them writable, so the node no longer carries a tell), and
     * formProjection() subtracts them from the attribute pass before
     * republishing their rows off the relationship — see
     * relationshipRepeaters().
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function leafNames(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'repeater') {
                if (is_string($node['name'] ?? null)) {
                    $names[] = $node['name'];
                }

                continue;
            }

            if (isset($node['children'])) {
                $names = [...$names, ...$this->leafNames($node['children'])];

                continue;
            }

            if (is_string($node['name'] ?? null)) {
                $names[] = $node['name'];
            }
        }

        return $names;
    }

    // stringQuery()/applySearch()/applySort() live in ListQuery, shared with
    // RelationController — one mechanism, two call sites.
}
