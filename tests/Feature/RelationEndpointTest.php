<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardOverriddenCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\DottedCardCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ExplodingCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GhostCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MisdeclaredCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\HiddenCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PermissiveCompanyResource;
use Filament\Panel;
use Filament\PanelRegistry;
use Gait\FilamentMobile\Http\RelationController;
use Gait\FilamentMobile\Tests\Fixtures\Resources\AdminOnlyCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardlessCompanyResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Re-registering the same id overwrites the entry, so a test that needs the
 * panel on a different guard just calls this again.
 */
function registerMobileTestPanel(string $authGuard = 'web'): void
{
    app(PanelRegistry::class)->register(
        Panel::make()->id('mobile-test')->authGuard($authGuard)->default(),
    );
}

/**
 * The panel on a guard of its own, which is the shape every documented
 * deployment has: the README's setup puts the mobile routes on `sanctum`
 * while the panel keeps its session guard. `auth:{guard}` rewrites the
 * DEFAULT guard (`Auth::shouldUse()`), so the bare `Gate` facade follows the
 * request's user — but nothing rewrites the panel's, which is why this is the
 * one identity the endpoint has to establish for itself.
 */
function panelGuardHolding(?string $name): void
{
    config()->set('auth.guards.panel', ['driver' => 'session', 'provider' => 'users']);
    registerMobileTestPanel('panel');

    if ($name !== null) {
        auth()->guard('panel')->setUser(makeUser($name));
    }
}

beforeEach(function () {
    // The one test file in this suite that needs a registered panel, and
    // not for its resources — the config list below is still the source of
    // those. Filament's DEFAULT `canViewForRecord` resolves its user through
    // `Filament::auth()`, which is the default panel's guard, so with no panel
    // registered it throws `NoDefaultPanelSetException` and the endpoint —
    // correctly, per "a gate that cannot answer refuses" — answers 403 to
    // everyone. A real app always has a default panel, so an empty registry
    // would have this file testing an environment no deployment has.
    registerMobileTestPanel();

    // Not the shared TestCase list: these resources exist for the relation
    // slice only, and belong here rather than skewing every other endpoint
    // test's resource count.
    config()->set('filament-mobile.resources', [
        BannerResource::class,
        CompanyResource::class,
        CardOverriddenCompanyResource::class,
        NarrowedCompanyResource::class,
        HiddenCompanyResource::class,
        ExplodingCompanyResource::class,
        PermissiveCompanyResource::class,
        AdminOnlyCompanyResource::class,
        CardlessCompanyResource::class,
        DottedCardCompanyResource::class,
        MisdeclaredCompanyResource::class,
        GhostCompanyResource::class,
    ]);
});

it('returns the parent record\'s children in the index envelope', function () {
    $acme = Company::create(['name' => 'Acme']);
    $other = Company::create(['name' => 'Other']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);
    Banner::create(['company_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'internal_note' => 'x']);

    $response = $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners");

    // `name`, not `title`: RecordSerializer emits the card's FIELD paths, so
    // a derived card of title=`name`, subtitle=`status` serialises those two
    // keys plus the route key. The slot names never reach the wire — see
    // ListEndpointTest, which asserts the same shape for a resource list.
    $response->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Mine')
        ->assertJsonPath('data.0.status', 'active');

    // The other company's row must not be reachable through this parent.
    expect(collect($response->json('data'))->pluck('name'))->not->toContain('Theirs');
});

it('serves a BelongsToMany relation, pivot and all', function () {
    // The HasMany above and this one paginate through different Eloquent
    // machinery and resolve their child model differently, so the shape the
    // endpoint is least likely to have been written against gets its own test.
    $mine = seedBanner('Mine');
    $theirs = seedBanner('Theirs');

    $red = Tag::create(['name' => 'Red']);
    $blue = Tag::create(['name' => 'Blue']);

    $mine->tags()->attach($red);
    $theirs->tags()->attach($blue);

    $response = $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$mine->id}/relations/tags");

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Red');

    // The pivot must actually scope the list: the other banner's tag is not
    // reachable through this parent.
    expect(collect($response->json('data'))->pluck('name'))->not->toContain('Blue');
});

it('serialises through the host-declared card when there is one', function () {
    // CardOverriddenCompanyResource declares the same relation manager as
    // CompanyResource — columns `name` then `status` — but overrides the card
    // to title=`status` only. The endpoint must apply the same precedence
    // /schema publishes, or the client is handed fields its card cannot render.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);

    $record = $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/card-overridden-companies/{$acme->id}/relations/banners")
        ->assertOk()
        ->json('data.0');

    expect($record)->toHaveKey('status')
        ->and($record)->not->toHaveKey('name');
});

it('404s for a resource nobody serves, before asking any gate', function () {
    $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/nothings/1/relations/banners')
        ->assertNotFound();
});

it('404s for a relation the resource does not publish', function () {
    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/nonexistent")
        ->assertNotFound();
});

it('404s for a refused relation rather than serving it unnarrowed', function () {
    // Not a 403: a 403 would suggest the relation might appear for someone
    // else. It does not exist as far as this API is concerned.
    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/narrowed-companies/{$acme->id}/relations/banners")
        ->assertNotFound();
});

it('403s when the parent resource itself is not viewable', function () {
    $acme = Company::create(['name' => 'Acme']);

    Gate::before(fn ($user, string $ability, array $arguments = []) => ($arguments[0] ?? null) === Company::class
        ? false
        : null);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners")
        ->assertForbidden();
});

it('403s for a parent record show() itself refuses', function () {
    // Gate 1 is "the existing show() authorization", and show() is two checks:
    // the class-level viewAny AND the record's own `view`. With only the first,
    // a record the API refuses to show handed over its children — and the 200
    // confirmed the row exists as well.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Secret', 'status' => 'active', 'internal_note' => 'x']);

    // The plainest ownership shape: viewAny allows, view refuses.
    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Company
            ? false
            : null);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}")
        ->assertForbidden();

    $response = $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners");

    $response->assertForbidden();

    expect($response->json('data'))->toBeNull()
        ->and($response->getContent())->not->toContain('Secret');
});

it('403s when canViewForRecord says no', function () {
    // Not an empty list: an empty list says "there is nothing here", which is
    // a different and false statement.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/hidden-companies/{$acme->id}/relations/banners")
        ->assertForbidden();
});

it('403s when canViewForRecord throws, and says so in the log', function () {
    // A gate that cannot answer refuses. It also leaves a trace: a deliberate
    // denial and a broken gate are the same 403 on the wire, and this line is
    // the only thing that tells them apart.
    Log::spy();

    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/exploding-companies/{$acme->id}/relations/banners")
        ->assertForbidden();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'relation gate could not answer'))
        ->once();
});

it('asks the relation gate about the request user, not whoever holds a panel session', function () {
    // The escalation. `AdminOnlyBannersRelationManager` reads
    // `Filament::auth()->user()` — the panel guard — so an unrelated admin's
    // session riding along on a token-authed request answered the gate for a
    // caller who is not that admin, and served the rows.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'AdminsOnly', 'status' => 'active', 'internal_note' => 'x']);

    panelGuardHolding('admin');

    $response = $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/admin-only-companies/{$acme->id}/relations/banners");

    $response->assertForbidden();

    // The body, not just the status: a 200 that leaks a row is the defect.
    expect($response->json('data'))->toBeNull()
        ->and($response->getContent())->not->toContain('AdminsOnly');
});

it('serves the request user their own rows with nobody on the panel guard', function () {
    // The other direction of the same bug, and the ordinary token-auth case:
    // the panel guard is empty on an API request, so a gate reading it
    // refused a user whose own answer is yes.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'AdminsOnly', 'status' => 'active', 'internal_note' => 'x']);

    panelGuardHolding(null);

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/admin-only-companies/{$acme->id}/relations/banners")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'AdminsOnly');
});

it('restores the panel guard identity even when the gate throws', function () {
    // The impersonation lasts exactly as long as the gate call. A gate that
    // throws must not leave the request's user standing on the panel's guard
    // for whatever runs next.
    $acme = Company::create(['name' => 'Acme']);

    panelGuardHolding('admin');

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/exploding-companies/{$acme->id}/relations/banners")
        ->assertForbidden();

    expect(auth()->guard('panel')->user()?->name)->toBe('admin');
});

it('refuses a gate it has no user to ask about', function () {
    // Reached by registering the controller WITHOUT the auth middleware every
    // route in routes.php carries, because that is the only way in: the branch
    // guards against a future route registration, not against today's. The
    // relation manager here allows unconditionally, so without the guard the
    // request walks through gate 2 and the response is the rows.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Unguarded', 'status' => 'active', 'internal_note' => 'x']);

    Route::get('probe/{resource}/{record}/relations/{relation}', RelationController::class);

    $response = $this->getJson("/probe/permissive-companies/{$acme->id}/relations/banners");

    $response->assertForbidden();

    expect($response->json('data'))->toBeNull()
        ->and($response->getContent())->not->toContain('Unguarded');
});

it('404s for a relation no card can be derived for, exactly as /schema omits it', function () {
    $acme = Company::create(['name' => 'Acme']);

    // The two answers are one decision: a relation the document does not
    // publish has no endpoint.
    expect(schemaFor('cardless-companies')['relations'])->toBe([]);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/cardless-companies/{$acme->id}/relations/banners")
        ->assertNotFound();
});

it('403s when the child model denies viewAny even though the relation gate allowed it', function () {
    // The third gate, isolated. PermissiveCompanyResource's manager overrides
    // canViewForRecord to an unconditional yes — replacing, not extending,
    // Filament's default child-model check — so this passes gate two and only
    // the independent check can refuse.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);

    Gate::before(fn ($user, string $ability, array $arguments = []) => ($arguments[0] ?? null) === Banner::class
        ? false
        : null);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/permissive-companies/{$acme->id}/relations/banners")
        ->assertForbidden();
});

it('404s for a parent the resource query hides, never 403', function () {
    // A 403 would confirm the row exists.
    $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/companies/999999/relations/banners')
        ->assertNotFound();
});

it('404s for a soft-deleted parent the resource query filters out', function () {
    // The stronger half of the rule above: the row exists, and the resource's
    // own query is the only thing hiding it.
    $banner = seedBanner('Mine');
    $banner->tags()->attach(Tag::create(['name' => 'Red']));
    softDeleteOneBanner();

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags")
        ->assertNotFound();
});

it('eager-loads the relations a dotted card field reads, exactly as index() does', function () {
    // The N+1 defence `MobileCard::relationPaths()` calls "automatic". It is
    // automatic on `index()` — `->with($card->relationPaths())` — and was not
    // carried over here, so a card with `subtitle('company.name')` cost one
    // extra query PER ROW, unbounded by per_page. A count, not an assertion
    // about `->with()`: the count is the thing that hurts, and it cannot pass
    // by accident.
    $acme = Company::create(['name' => 'Acme']);

    for ($i = 0; $i < 10; $i++) {
        Banner::create(['company_id' => $acme->id, 'name' => "B{$i}", 'status' => 'active', 'internal_note' => 'x']);
    }

    $user = makeUser('viewer');

    DB::enableQueryLog();

    $this->actingAs($user)
        ->getJson("/api/mobile-panel/dotted-card-companies/{$acme->id}/relations/banners")
        ->assertOk()
        ->assertJsonPath('data.0.company.name', 'Acme');

    $relationQueries = count(DB::getQueryLog());

    DB::flushQueryLog();

    $this->actingAs($user)
        ->getJson('/api/mobile-panel/dotted-card-companies')
        ->assertOk();

    $listQueries = count(DB::getQueryLog());

    DB::disableQueryLog();

    // Not an absolute number — the two paths differ by a parent lookup — but
    // the relation path must not scale with the rows it serves. Unfixed this
    // read 14 against 4.
    expect($relationQueries)->toBeLessThanOrEqual($listQueries + 2);
});

it('404s for a relation whose declared card fills no slot', function () {
    // "No card" is a rule about what can be RENDERED, not about null. A host
    // calling `relationCard('banners', fn ($card) => $card)` produces a
    // non-null card with no slots, which shipped rows carrying nothing but
    // their id — the disabled corpse the design forbids.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);

    expect(schemaFor('misdeclared-companies')['relations'])->toBe([]);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/misdeclared-companies/{$acme->id}/relations/banners")
        ->assertNotFound();
});

it('404s for a relation whose relationship does not resolve on the model', function () {
    // Published, this was a control that cannot work: `/schema` advertised
    // `ghosts`, and the endpoint answered 403 — via gate 2, which refuses
    // because the DEFAULT canViewForRecord cannot resolve the related model
    // either. A relation this package cannot serve is absent, never published
    // and then refused.
    $acme = Company::create(['name' => 'Acme']);

    expect(schemaFor('ghost-companies')['relations'])->toBe([]);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/ghost-companies/{$acme->id}/relations/ghosts")
        ->assertNotFound();
});

it('paginates with the configured page size', function () {
    $acme = Company::create(['name' => 'Acme']);

    for ($i = 0; $i < 25; $i++) {
        Banner::create(['company_id' => $acme->id, 'name' => "B{$i}", 'status' => 'active', 'internal_note' => 'x']);
    }

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners?page=2")
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(5, 'data');
});
