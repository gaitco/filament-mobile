<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Filament\Facades\Filament;
use Gait\FilamentMobile\Authorizer;
use Gait\FilamentMobile\Introspection\RelationDiscovery;
use Gait\FilamentMobile\RecordSerializer;
use Gait\FilamentMobile\ResourceRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One relation manager's child rows, read-only and paginated.
 *
 * The parent is resolved from the URL and authorized before the relation is
 * touched — a relation is never scoped by a client-supplied parent id, which
 * is the whole reason this is a nested route rather than a filter on
 * `index()`.
 */
final class RelationController
{
    public function __construct(private readonly ResourceRegistry $registry)
    {
    }

    public function __invoke(Request $request, string $resource, string $id, string $relation): JsonResponse
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
        $published = collect(RelationDiscovery::for($class, $mobile))
            ->firstWhere('key', $relation)
            ?? abort(404, "No relation [{$relation}] on [{$resource}].");

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
        $related = $relationship->getRelated();

        // Gate 3 — the child model's own viewAny. Redundant with Filament's
        // DEFAULT canViewForRecord (which calls authorize('viewAny', $model)),
        // and not redundant with an overridden one, which replaces that check
        // rather than adding to it.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $related::class),
            403,
        );

        // THE published card — the one /schema resolved, not a second
        // resolution that could disagree with it. Cardless is how /schema
        // omits a relation entirely, so the endpoint for one was never
        // published either and the 404 above has already fired.
        $card = $published['card'];

        // The same N+1 defence `index()` applies, for the same reason and
        // from the same source: `relationPaths()` is derived from the card's
        // dotted fields, so a `subtitle('company.name')` costs one query for
        // the page instead of one per row. `index()` has had this since P1 and
        // this endpoint — documented as mirroring it "exactly" — did not:
        // measured at 14 queries for 10 rows against index()'s 4.
        $records = $relationship
            ->with($card->relationPaths())
            ->paginate(config('filament-mobile.per_page'));

        $serializer = new RecordSerializer($card, $related->getRouteKeyName());

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
