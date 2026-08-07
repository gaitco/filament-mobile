<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Filament\Actions\Action;
use Filament\Actions\Enums\ActionStatus;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Gait\FilamentMobile\Actions\ActionResolver;
use Gait\FilamentMobile\Authorizer;
use Gait\FilamentMobile\Introspection\FormDefaults;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Introspection\RichContent;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\TagSeparators;
use Gait\FilamentMobile\Introspection\WalkWarnings;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\RecordSerializer;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Gait\FilamentMobile\Write\SettledSchema;
use Gait\FilamentMobile\Write\WritableNames;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        // The resource's own query, never the model's: global scopes, soft
        // deletes and tenancy are inherited rather than reimplemented here.
        $query = $class::getEloquentQuery()
            // Derived from the card's dotted paths, so the N+1 defence needs
            // no declaration and cannot drift from what is serialised.
            ->with($card->relationPaths());

        $this->applySearch($query, $this->stringQuery($request, 'search'), $mobile->getSearchable());
        $this->applySort($query, $request, $mobile);

        $records = $query->paginate(config('filament-mobile.per_page'));

        $serializer = new RecordSerializer($card, (new ($class::getModel())())->getRouteKeyName(), $class);

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
                $this->formComponents($class, [], record: null),
            ),
            build: function (array $state) use ($class): array {
                $components = $this->formComponents($class, $state, record: null);

                return ['components' => $components, 'writable' => WritableNames::of($components)];
            },
        );

        $components = $settled->components();

        $validated = $request->validate(
            $this->allowedRules($settled),
            attributes: $this->validationAttributes($settled),
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
            $this->fillMissingPaths($validated, FormDefaults::fromComponents($components)),
            $components,
        ));

        $this->saveRelations($class, $settled->state(), $record, 'create', $request->all());

        $serializer = new RecordSerializer($mobile->getCard(), $record->getRouteKeyName(), $class);

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
        $infolist = $this->infolistNodes($class, $resource, $attributes);

        $serializer = (new RecordSerializer($mobile->getCard(), $model->getRouteKeyName(), $class))
            ->withInfolistPaths($this->leafNames($infolist))
            ->withFormPaths($this->formPaths($class, $resource, $attributes, $record))
            ->withRichPaths(RichContent::entryNamesIn($infolist));

        $resolver = new ActionResolver($class, $mobile);

        return response()->json([
            // Not wrapped in TagSeparators::hydrate() here, deliberately: the
            // read half lives inside RecordSerializer, so index() and the
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
                $components = $this->formComponents($class, $state, $record);

                return ['components' => $components, 'writable' => WritableNames::of($components)];
            },
        );

        $rules = $this->allowedRules($settled);

        $validated = $request->validate(
            $rules,
            attributes: $this->validationAttributes($settled),
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
            $this->fillMissingPaths($validated, $this->storedPaths($record, array_keys($rules))),
            $settled->components(),
        ));

        $this->saveRelations($class, $settled->state(), $record, 'edit', $request->all());

        $serializer = new RecordSerializer($mobile->getCard(), $record->getRouteKeyName(), $class);

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
     * The settled schema's rules, narrowed to the names the settle actually
     * allowed — which is the mass-assignment whitelist, so this is the one
     * place both write endpoints decide what may reach the database.
     *
     * The intersection is not belt-and-braces. The returned components are the
     * FINAL pass's, built from state whose non-writable paths were already
     * reset, so that build can report a name the allow-set dropped on an
     * earlier pass: `dehydrated(fn (?string $state) => filled($state))` refuses
     * the submitted `''`, the reset restores the stored value, and the next
     * build then says "writable" — about a value the client never sent. Taking
     * the rules off the build alone lets it through, and the column lands NULL
     * (ConvertEmptyStringsToNull got the `''` first). See
     * SettledSchema::writable().
     *
     * ponytail: the converse is a known, accepted residual — shrink-only can
     * discard a legitimate write behind a 200. Stored `kind='unlock'` opens
     * `gate_note`; the client PUTs `{"kind":"promo","gate_note":"..."}`. Pass 1
     * drops `gate_note` (the submitted `kind` closes it), the reset restores
     * the stored `kind='unlock'`, pass 2 reports it writable again, and
     * shrink-only refuses — 200 OK, typing silently discarded. Reachability is
     * low: `kind` is a Hidden, so a client can never learn it and must invent a
     * contradicting value for a name it was never shown. The upgrade path is a
     * per-field refusal report in the response, which is a contract change, not
     * a fix here.
     *
     * P6c Task 3 finding, fixed here rather than worked around in a test: a
     * plain `array_intersect_key` against `$settled->writable()` was an exact-
     * key match, which held for every rule name before repeaters because
     * `WritableNames::of()` USED to be `array_keys(RuleExtractor::
     * fromComponents(...))` — the same set, by construction. P6c broke that
     * identity on purpose (see the design spec's "two different name
     * spaces"): a repeater's rules also carry per-item paths
     * (`line_items.*.sku`), but `WritableNames` deliberately names only the
     * whole-array `line_items` — `Arr::has()`/`Arr::set()` have no wildcard
     * support, so a starred name may never enter the settle's allow-set.
     * The exact-key intersection this method used to do therefore matched
     * `line_items` but never `line_items.*.sku`, silently dropping every
     * per-item rule from what `$request->validate()` was ever given — a row
     * with an empty required `sku` validated as if the child rule did not
     * exist. `isRuleNameAllowed()` is the fix: an exact match still wins for
     * an ordinary field, and a `name.*.` prefix wins only when `name` ITSELF
     * is writable — so a per-item rule is admitted exactly when its owning
     * repeater is, and a disabled or relationship repeater's per-item rules
     * stay excluded right alongside its own, same as before.
     *
     * @return array<string, mixed>
     */
    private function allowedRules(SettledSchema $settled): array
    {
        $writable = $settled->writable();

        return array_filter(
            RuleExtractor::fromComponents($settled->components()),
            static fn (string $name): bool => self::isRuleNameAllowed($name, $writable),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * `:attribute` for the rules above, so the 422 names the field the way
     * `/schema`'s published `rules.messages` already does.
     *
     * Both come from the field's `getValidationAttribute()` — Filament's own
     * label-aware attribute, which is what the web panel shows. Without this
     * the validator falls back to the humanised column name and the two
     * describe the same rule with two different nouns. The pilot measured
     * the disagreement on 137 of 187 constrained fields, and on 148 of 187
     * once the panel's locale was Arabic, where the 422 read
     * "title.ar مطلوب" against a published "الاسم (عربي) مطلوب".
     *
     * Filtered by the same predicate as allowedRules(), for the same reason
     * and since the same Task 3 finding: an attribute for a name no rule
     * mentions is inert, but a narrower filter here would make this method
     * and allowedRules() disagree about which fields exist — a repeater's
     * per-item attribute (`line_items.*.sku`) must survive here exactly when
     * its rule does, or its 422 falls back to the humanised path instead of
     * the field's own label.
     *
     * @return array<string, string>
     */
    private function validationAttributes(SettledSchema $settled): array
    {
        $writable = $settled->writable();

        return array_filter(
            RuleExtractor::attributesFrom($settled->components()),
            static fn (string $name): bool => self::isRuleNameAllowed($name, $writable),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether a RuleExtractor key may reach `$request->validate()`. Three
     * shapes, all keyed off a name that is writable in its own right:
     *
     *  - the exact name — every ordinary field, and a repeater's or a tags
     *    field's own whole-array name;
     *  - `line_items.*.sku` — a repeater's per-item path, which has a child
     *    segment because a repeater has child components;
     *  - `labels.*` — a tags field's per-TAG path, which has NO child
     *    segment because a TagsInput has no children: the per-element rules
     *    are the component's own, through
     *    `HasNestedRecursiveValidationRules`. P7 Task 2 measured this: the
     *    `.*.` prefix alone (the only shape that existed before tags) matched
     *    `line_items.*.sku` and never `labels.*`, so every per-tag rule was
     *    extracted, published, and then dropped here — a 21-character tag
     *    behind `->nestedRecursiveRules(['max:20'])` saved with a 200.
     *
     * See allowedRules()'s docblock for why the exact-match-only predicate
     * that preceded all of this was silently dropping every repeater child
     * rule.
     *
     * @param  list<string>  $writable
     */
    private static function isRuleNameAllowed(string $name, array $writable): bool
    {
        foreach ($writable as $allowed) {
            if ($name === $allowed
                || $name === $allowed . '.*'
                || str_starts_with($name, $allowed . '.*.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one way this package merges anything into a payload: path by path,
     * writing only what the payload does not already answer for.
     *
     * Never array_replace_recursive() and never a spread. PHP has no array
     * merge that is correct for both halves of what arrives here: a spread
     * replaces a whole `caption` map, dropping the locales the payload did not
     * send, and array_replace_recursive() merges LISTS BY INDEX — a default of
     * `['a','b','c']` under a submitted `['c']` stores `['c','b','c']`, a
     * silent corruption of the user's own choice. Trading one for the other is
     * how this bug came back twice.
     *
     * `Arr::has()` is the whole discrimination, and it is per path: the payload
     * answers for `caption.en` but not `caption.ar`, and it answers for
     * `plain_multi` as one indivisible value. An explicitly submitted null is
     * an answer — Arr::has() reads key presence, not truthiness — so a client
     * may still clear a field that has a default.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $paths  flat `path => value`
     * @return array<string, mixed>
     */
    private function fillMissingPaths(array $payload, array $paths): array
    {
        foreach ($paths as $path => $value) {
            if (Arr::has($payload, $path) || $this->collidesWithScalar($payload, $path)) {
                continue;
            }

            data_set($payload, $path, $value);
        }

        return $payload;
    }

    /**
     * Whether an ancestor of this path is already answered by a NON-array
     * value — `caption` submitted as text while a `caption.ar` default waits to
     * be filled in underneath it.
     *
     * `data_set` would replace that text with `['ar' => ...]`, i.e. the default
     * beating the user's own input, which no merge here may ever do. The
     * payload wins: it has answered for the whole attribute, so there is
     * nothing left underneath it to fill.
     *
     * This is the WRITE half of the `title` / `title.ar` collision spec §9
     * records on the serializer, and it is not resolved here: a panel naming
     * both a scalar and its dotted children still gets only one of the two
     * shapes. That is a P3 contract task. This only decides which one loses —
     * the default, never the submission.
     */
    private function collidesWithScalar(array $payload, string $path): bool
    {
        $segments = explode('.', $path);
        array_pop($segments);
        $prefix = '';

        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            if (Arr::has($payload, $prefix) && ! is_array(Arr::get($payload, $prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record's stored values for the attributes a dotted rule path names,
     * flattened to the same flat `path => value` shape FormDefaults returns.
     *
     * Only those attributes: an undotted path writes its attribute whole, so
     * there is nothing to preserve underneath it, and re-listing every column
     * would put values the form never mentioned into the update array.
     *
     * A list stays a leaf. Descending into one would reintroduce exactly the
     * merge-by-index corruption fillMissingPaths() exists to refuse.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function storedPaths(Model $record, array $paths): array
    {
        $stored = [];

        foreach ($paths as $path) {
            if (! str_contains($path, '.')) {
                continue;
            }

            $attribute = explode('.', $path, 2)[0];
            $value = $record->getAttribute($attribute);

            if (is_array($value)) {
                $stored = [...$stored, ...$this->leafPaths([$attribute => $value])];
            }
        }

        return $stored;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function leafPaths(array $values, string $prefix = ''): array
    {
        $paths = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = [...$paths, ...$this->leafPaths($value, $path)];

                continue;
            }

            $paths[$path] = $value;
        }

        return $paths;
    }

    /**
     * The form as it stands for these values — the one place the write path
     * builds a schema, so store() and update() cannot drift apart on which
     * fields exist.
     *
     * The host is seeded with the submitted values, not left empty, because
     * `getComponents()` filters hidden components and a hidden field yields no
     * rule: extracting against an empty form would silently drop the value of
     * every field a `visible(fn (Get $get) => ...)` closure reveals. It also
     * cannot be a null host — `Schema::make()` accepts one, but the first such
     * closure then fatals inside `isHidden()` on `Schema::getLivewire()`, whose
     * return type is not nullable: a 500 on every write to a reactive form.
     *
     * The record is what makes this differ between store() and update(), and
     * it carries both halves: `Schema::record()` *is* `model()`, and the
     * operation is derived from it rather than passed separately, so the two
     * can never disagree about which one this is.
     *
     * ponytail: accepted residual — plain `getComponents()`, not
     * `getComponents(withHidden: true)` the way StateController's read side
     * calls it. A top-level `->hidden()->dehydratedWhenHidden()` field
     * genuinely IS writable (`isDehydrated()` only excludes a field that is
     * hidden and NOT re-dehydrated-when-hidden), but this call drops it before
     * SettledSchema or WritableNames ever sees it, so it silently never
     * writes even though `/state` — which does pass `withHidden: true` —
     * reports it writable. Exotic shape; the honest fix is passing
     * `withHidden: true` on this path too, not a filter over on `/state`.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return list<object>
     */
    /**
     * The relation pass: what Filament's own CreateRecord/EditRecord run as
     * `$this->form->model($record)->saveRelationships()` after the attribute
     * save. A `Select::multiple()->relationship()` has no column — this is
     * its only way into the database.
     *
     * Rebuilt from the SETTLED state rather than reusing the settle's own
     * components, because store()'s settle ran before the record existed and
     * `BelongsToModel::saveRelationships()` refuses without an existing
     * record. The state is the settled one, so every gate the rebuild
     * answers is evaluated against values no crafted payload could have
     * steered — same property the validation pass relies on.
     *
     * The `Arr::has($payload, ...)` guard is the difference between absent
     * and empty, and it is load-bearing: a relation the request never
     * mentioned is not in the settled state (a BelongsToMany is not an
     * attribute, so the trusted floor never carries it), and syncing that
     * absence would CLEAR a pivot the user never touched. Explicit `[]`
     * stays a deliberate clear.
     *
     * The disabled refusal lives in the descent (relationWriteComponents),
     * fail closed — a disabled picker's crafted ids neither attach nor
     * degrade into a clearing sync. A sync that genuinely throws propagates:
     * turning it into a 200 would be the silent data loss this package
     * refuses everywhere else.
     *
     * @param  array<string, mixed>  $state  the settled state
     * @param  array<string, mixed>  $payload  the raw request payload
     */
    private function saveRelations(string $class, array $state, Model $record, string $operation, array $payload): void
    {
        $components = $class::form(
            $this->formSchema($class, $state, $operation, $record),
        )->getComponents();

        foreach (RuleExtractor::relationWriteComponents($components) as $name => $component) {
            if (! Arr::has($payload, $name)) {
                continue;
            }

            $component->saveRelationships();
        }
    }

    private function formComponents(string $class, array $state, ?Model $record): array
    {
        return $class::form($this->formSchema(
            $class,
            $state,
            $record === null ? 'create' : 'edit',
            $record,
        ))->getComponents();
    }

    /**
     * The one Schema construction every endpoint here goes through.
     *
     * `Schema::make()` accepts a null host, but that is what makes any
     * `visible(fn (Get $get) => ...)` fatal inside `isHidden()` — see
     * formComponents() above.
     *
     * `->model()` is what Filament's own resource pages set. Without it a
     * `Select::relationship()` resolves no options at all (`getRelationship()`
     * reaches for the schema's model instance and fails on null), so a required
     * foreign key arrives at the phone as an empty picker and every write to
     * that resource 422s on a field the client had no legal value for.
     *
     * `->operation()` is not optional either, and nothing in this package set
     * it until review found the hole. `Schema::getOperation()` falls through to
     * `getLivewire()::class` when unset — here, `HeadlessSchemaHost` — which
     * matches neither `'create'`/`'edit'` nor the `instanceof` branch inside
     * `disabledOn()`/`hiddenOn()`/`visibleOn()`. **Every** operation-scoped
     * gate therefore evaluated false, and `disabledOn('edit')` is *the*
     * idiomatic immutable-after-create gate: slug, sku, email, type.
     *
     * `$record` is passed only where there genuinely is one. It is what makes
     * `disabled(fn (?Model $record) => ...)` answer for the row being edited
     * instead of answering as a create, and a non-nullable `Model $record`
     * hint stop throwing.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     */
    private function formSchema(string $class, array $state, string $operation, ?Model $record = null): Schema
    {
        return Schema::make($this->host($state))
            ->model($record ?? $class::getModel())
            ->operation($operation);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function host(array $state): HeadlessSchemaHost
    {
        $host = new HeadlessSchemaHost();
        $host->setMobileState($state);

        return $host;
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
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function infolistNodes(string $class, string $resourceKey, array $state): array
    {
        $walker = new SchemaWalker(new WalkWarnings());

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
            // record — see formComponents().
            $components = $class::infolist($this->formSchema($class, $state, 'view'))
                ->getComponents();
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
     * Unlike infolistPaths(), this DOES pass the record: formComponents()
     * already does so on every write path, and an edit form that resolves its
     * gates as a create is the defect Task 6 of P2-Laravel closed. The
     * try/catch mirrors infolistPaths(): a throwing form costs the prefill,
     * not the whole detail screen.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function formPaths(string $class, string $resourceKey, array $state, Model $record): array
    {
        $walker = new SchemaWalker(new WalkWarnings());

        try {
            $components = $this->formComponents($class, $state, $record);
        } catch (Throwable $e) {
            Log::warning('[filament-mobile] could not build the form schema for '
                . class_basename($class) . ', the edit form will open unprefilled: '
                . $e->getMessage());

            return [];
        }

        return $this->leafNames($walker->walk($components, class_basename($class), $resourceKey));
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
     * A RELATIONSHIP repeater is the one exception: it has no column of its
     * own — `Repeater::relationship()` writes child rows through Filament's
     * own saveRelationships(), never an attribute on this model — so
     * collecting its name would have RecordSerializer read a non-existent
     * attribute and publish it as an ordinary null column instead of leaving
     * it absent the way every other read-only, no-column thing on this
     * contract is absent.
     *
     * It is identified by `config.readOnly` AND `writable: false` together,
     * and both halves are load-bearing. `relationship()` earns both (it sets
     * `dehydrated(false)` as a literal, which is what `neverPersists()`
     * publishes as `writable: false`). `readOnly` ALONE stopped meaning "no
     * column" when P6c's close-out started publishing it for a nested
     * repeater and for one whose item template holds a child that would not
     * round-trip — both of which have a real column whose stored rows must
     * still be READABLE, since refusing the control is precisely how their
     * data is being protected. Keying on `readOnly` alone silently dropped
     * those columns from every GET.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function leafNames(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'repeater') {
                if (is_string($node['name'] ?? null)
                    && ! (($node['config']['readOnly'] ?? false) === true && ($node['writable'] ?? true) === false)) {
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

    /**
     * The one place a query parameter becomes a PHP value. `?sort[]=x` binds an
     * array, and every scalar string function below would fatal on it — a 500
     * for input a client fully controls. Non-strings are rejected with the same
     * 422 the sort path already promises, and an empty value is read as absent
     * (`?sort=` is an ordinary way to say "no sort", not an unknown key).
     */
    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        abort_if($value !== null && ! is_string($value), 422, "[{$key}] must be a string.");

        return ($value === null || $value === '') ? null : $value;
    }

    /** @param list<string> $columns */
    private function applySearch(Builder $query, ?string $search, array $columns): void
    {
        if ($search === null || $columns === []) {
            return;
        }

        // `%` and `_` are LIKE metacharacters: unescaped, `?search=%` matches
        // every row and the filter stops filtering. The ESCAPE clause is
        // explicit because SQLite — unlike MySQL and Postgres — has no default
        // escape character.
        //
        // The escape character is `!`, not the conventional backslash. MySQL
        // treats a backslash as an escape *inside string literals*, so the
        // clause `escape '\'` is an unterminated literal and every search
        // request is a 1064 syntax error — which SQLite, where the package's
        // tests run, parses happily as a literal backslash. Writing `escape
        // '\\'` would fix MySQL and break SQLite, which reads it as a
        // two-character escape string and rejects it. `!` needs no escaping in
        // a string literal on any of the three drivers, so one clause is
        // correct everywhere.
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        // One where group, not a chain of top-level orWheres: a bare orWhere
        // would escape any scope the resource query already applied and widen
        // the result set past it.
        // ponytail: plain columns only. A dotted searchable() would need
        // whereHas — add it the day a resource declares one.
        $query->where(function (Builder $group) use ($term, $columns): void {
            foreach ($columns as $column) {
                $group->orWhereRaw(
                    $group->getGrammar()->wrap($column) . " like ? escape '!'",
                    [$term],
                );
            }
        });
    }

    private function applySort(Builder $query, Request $request, MobileResource $mobile): void
    {
        $requested = $this->stringQuery($request, 'sort');
        $key = $requested ?? $mobile->getDefaultSortKey();

        if ($key === null) {
            return;
        }

        // An undeclared key is an error, never a silently ignored parameter:
        // a client sorting by a column that does not exist must find out.
        abort_unless(array_key_exists($key, $mobile->getSorts()), 422, "Unknown sort key [{$key}].");

        $direction = $this->stringQuery($request, 'direction')
            ?? ($key === $mobile->getDefaultSortKey() ? $mobile->getDefaultSortDirection() : 'asc');

        $query->orderBy($key, strtolower($direction) === 'desc' ? 'desc' : 'asc');
    }
}
