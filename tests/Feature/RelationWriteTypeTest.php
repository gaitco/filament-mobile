<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BelongsToBannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;

/**
 * P9 review finding: `store()` creates THROUGH the relationship so the foreign
 * key is the parent's by construction — but that only holds for the relation
 * types whose `create()` actually links. `RelationDiscovery` published any
 * `Relation` subtype, so a `BelongsTo`, `MorphTo`, `HasManyThrough` or
 * `HasOneThrough` relation manager whose child model had exactly one mobile
 * resource was published WRITABLE, and `Relation::__call` then forwarded
 * `create()` to the query builder: a `201` for a row created unrelated to the
 * parent, which the client's own refresh would not find.
 *
 * The read path is untouched — reading a BelongsTo relation is perfectly fine.
 * Only the write capability is withheld.
 */
beforeEach(function () {
    // BOTH registered: Company is served by exactly one mobile resource, so
    // the owner-count rule would publish the write. The relation TYPE is the
    // only thing withholding it here.
    config()->set('filament-mobile.resources', [
        BelongsToBannerResource::class,
        CompanyResource::class,
    ]);
});

function belongsToRelationNode(): array
{
    $resource = collect(schemaDocument()['resources'])->firstWhere('key', 'banners');

    return collect($resource['relations'])->firstWhere('key', 'company');
}

it('still publishes the relation for READING', function () {
    $node = belongsToRelationNode();

    expect($node)->not->toBeNull()
        ->and($node)->toHaveKey('card');
});

it('withholds the resource key, so the relation is read-only', function () {
    expect(belongsToRelationNode())->not->toHaveKey('resource');
});

it('404s a create against a relation a create cannot link', function () {
    $banner = seedBanner();
    $before = Company::query()->count();

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/relations/company", [
            'name' => 'Floating Co',
        ])
        ->assertStatus(404);

    // The assertion that matters: no orphan was written. Before the fix this
    // answered 201 and inserted a Company nothing pointed at.
    expect(Company::query()->count())->toBe($before)
        ->and(Company::query()->where('name', 'Floating Co')->exists())->toBeFalse();
});

it('404s an update and a delete on the same relation', function () {
    $banner = seedBanner();
    $company = $banner->company;

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}/relations/company/{$company->id}", [
            'name' => 'Renamed',
        ])
        ->assertStatus(404);

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/banners/{$banner->id}/relations/company/{$company->id}")
        ->assertStatus(404);

    expect($company->fresh()->name)->toBe('Acme');
});

// Deliberately NOT asserting the read ENDPOINT here. Under Testbench this
// manager's `canViewForRecord()` raises NoDefaultPanelSetException, the gate
// fails closed and logs, and the request is a 403 — an artefact of the harness
// having no default panel, not a fact about BelongsTo. The schema-level test
// above is the honest read-side assertion; endpoint read coverage lives in the
// relation tests whose managers the harness can gate.

it('doctor names the relation TYPE, not a missing resource', function () {
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('would not link the')
        ->assertExitCode(0);
});
