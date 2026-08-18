<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\PanelRegistry;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\HiddenCompanyResource;

/**
 * P11: the relation endpoint answers `?search=`/`?sort=`/`?direction=`
 * exactly as the resource index answers them (ListEndpointTest pins the
 * index half), against BannerResource's declared `tags` relation and
 * CompanyResource's undeclared `banners` one.
 */
beforeEach(function () {
    // Same idiom RelationSnapshotTest uses: the endpoint's authorization
    // resolves its user through the default panel, so one must exist.
    app(PanelRegistry::class)->register(
        Panel::make()->id('mobile-test')->default(),
    );

    config()->set('filament-mobile.resources', [
        BannerResource::class,
        CompanyResource::class,
        HiddenCompanyResource::class,
    ]);
});

/**
 * A banner with the given tags attached, in the order given — creation
 * order is what a default sort has to visibly override.
 *
 * @param  list<string>  $names
 */
function seedBannerWithTags(array $names): Banner
{
    $banner = seedBanner('Mine');

    foreach ($names as $name) {
        $banner->tags()->attach(Tag::create(['name' => $name]));
    }

    return $banner;
}

it('searches the relation over its declared columns', function () {
    $banner = seedBannerWithTags(['Red', 'Blue']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?search=red")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Red');
});

it('keeps the search inside the relationship constraint', function () {
    // The one-where-group rule, on a relation: a bare orWhere chain would
    // escape the pivot scope and find tags belonging to OTHER banners.
    $mine = seedBannerWithTags(['Red']);

    seedBanner('Theirs')->tags()->attach(Tag::create(['name' => 'Crimson']));

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$mine->id}/relations/tags?search=crimson")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('treats every LIKE metacharacter as a literal on a relation search', function (string $term) {
    // The index's escaping, pinned against the relation query: `!` is the
    // escape character, so it must be escaped first or escaping becomes
    // self-corrupting.
    $banner = seedBannerWithTags(['plain', "literal {$term} here"]);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?search=" . urlencode($term))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', "literal {$term} here");
})->with(['%', '_', '!', '!%', '%_!']);

it('sorts the relation by a declared key and direction', function () {
    $banner = seedBannerWithTags(['Red', 'Blue']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?sort=name&direction=asc")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Blue')
        ->assertJsonPath('data.1.name', 'Red');

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?sort=name&direction=desc")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Red');
});

it('applies the relation\'s declared default sort when no sort is requested', function () {
    // Red was created first, so creation order leads with Red; the declared
    // default (name asc) must override it. `?direction=` alone plays against
    // the default key, exactly as on the index.
    $banner = seedBannerWithTags(['Red', 'Blue']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Blue')
        ->assertJsonPath('data.1.name', 'Red');

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?direction=desc")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Red');
});

it('rejects an undeclared relation sort key with 422 rather than ignoring it', function () {
    $banner = seedBannerWithTags(['Red']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?sort=id")
        ->assertStatus(422);
});

it('rejects an array-typed query parameter with 422 rather than fataling', function (string $param) {
    $banner = seedBannerWithTags(['Red']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags?{$param}[]=x")
        ->assertStatus(422);
})->with(['search', 'sort', 'direction']);

it('lets a search against an undeclared relation pass through inert', function () {
    // CompanyResource declares no relationSearchable for banners: enabled is
    // false, so there is nothing to apply — the parameter did not claim a
    // capability, and every row comes back.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);
    Banner::create(['company_id' => $acme->id, 'name' => 'AlsoMine', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners?search=nomatch")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('still 422s a sort key on a relation that declares no sorts at all', function () {
    // The asymmetry the spec fixes: an undeclared search is inert, but a
    // sort parameter claims a capability the relation never published.
    $acme = Company::create(['name' => 'Acme']);
    Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners?sort=name")
        ->assertStatus(422);
});

it('answers 404 for an unknown record even with a bad sort parameter', function () {
    // Parameter validation runs AFTER the full gate sequence: a 422 must
    // never leak whether a relation or record exists.
    $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/companies/999999/relations/banners?sort=bogus')
        ->assertNotFound();
});

it('answers 404 for an unpublished relation even with a bad sort parameter', function () {
    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/nonexistent?sort=bogus")
        ->assertNotFound();
});

it('answers 403 for a refused relation even with a bad sort parameter', function () {
    // HiddenCompanyResource's canViewForRecord says no: the gate wins over
    // parameter validation, or the 422 would confirm the relation exists.
    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/hidden-companies/{$acme->id}/relations/banners?sort=bogus")
        ->assertForbidden();
});
