<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardOverriddenCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;

beforeEach(function () {
    // Not the shared TestCase list: these resources exist for this slice
    // only, and belong here rather than skewing every other endpoint test's
    // resource count.
    config()->set('filament-mobile.resources', [
        PostResource::class,
        BannerResource::class,
        CompanyResource::class,
        NarrowedCompanyResource::class,
        CardOverriddenCompanyResource::class,
    ]);
});

it('publishes a relation with its key, label and card', function () {
    $relations = schemaFor('companies')['relations'];

    expect($relations)->toHaveCount(1)
        ->and($relations[0]['key'])->toBe('banners')
        // MobileCard::toArray() wraps each slot as ['field' => …] and omits
        // an absent slot entirely — verified in Task 2, not assumed.
        ->and($relations[0]['card']['title']['field'])->toBe('name');
});

it('omits a refused relation entirely rather than publishing it disabled', function () {
    // Absence means unavailable. A disabled corpse would have the client
    // render a section that can never load.
    expect(schemaFor('narrowed-companies')['relations'])->toBe([]);
});

it('publishes an empty relations array for a resource with none', function () {
    // `banners` no longer exemplifies this (P6d Task 5 gave BannerResource a
    // TagsRelationManager, so the golden snapshot carries a populated
    // relation end-to-end) — `posts` does: PostResource declares no
    // getRelations() at all. The key is always present so a client never
    // branches on its absence.
    expect(schemaFor('posts'))->toHaveKey('relations')
        ->and(schemaFor('posts')['relations'])->toBe([]);
});

it('publishes the related model\'s own route key as recordKey', function () {
    // Banner never overrides getRouteKeyName(), so this is the default case
    // — and on its own would pass even a hardcoded `'id'`. The genuine proof
    // is the next test.
    $relations = schemaFor('companies')['relations'];

    expect($relations[0]['recordKey'])->toBe('id');
});

it('publishes a non-id recordKey when the related model uses one', function () {
    // Tag::getRouteKeyName() returns `name`, not `id` — the shape a slug- or
    // uuid-routed child ordinarily has. A client relying on this key to parse
    // `ResourceRecord`s from `/relations/tags` rows must see `name`, not a
    // hardcoded `id` the row does not even carry.
    $relations = schemaFor('banners')['relations'];
    $tags = collect($relations)->firstWhere('key', 'tags');

    expect($tags['recordKey'])->toBe('name');
});

it('publishes the host-declared relation card in place of the derived one', function () {
    // CardOverriddenCompanyResource declares the same relation as
    // CompanyResource above — BannersRelationManager, columns `name` then
    // `status` — so RelationCard::fromColumns() would derive a title of
    // `name`. The host instead calls relationCard('banners', …)->title('status').
    // Asserting `status` reached the wire fails if the precedence were
    // reversed (derived card winning over the host's), which nothing before
    // this test exercised: MobileResource::getRelationCard() had no caller.
    $relations = schemaFor('card-overridden-companies')['relations'];

    expect($relations[0]['card']['title']['field'])->toBe('status');
});
