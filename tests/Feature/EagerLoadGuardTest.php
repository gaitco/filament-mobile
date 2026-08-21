<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MedialessCardResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TaglessCardCompanyResource;

/**
 * Final review, finding 1: `MobilePanelController::index()`'s card-bound
 * eager load (`$query->with('tags')`/`$query->with('media')`) was gated
 * only on `cardTagPaths()`/`cardMediaPaths()` being non-empty — both
 * schema-only computations (see their docblocks) that say nothing about
 * whether the MODEL actually declares the relation. `TaglessCardCompanyResource`
 * and `MedialessCardResource` bind a card slot to a Spatie tags/media path on
 * `Company`, which has neither `HasTags` nor `HasMedia` — Eloquent's eager
 * load then calls a `tags()`/`media()` relation method that does not exist,
 * a `RelationNotFoundException` → 500, for any request with >= 1 row.
 * `RecordSerializer`'s own `method_exists` gate protects the READ once the
 * row is in hand; it never protected this eager-load line.
 */
it('lists a tagless-card resource without 500ing once a row exists', function () {
    config()->set('filament-mobile.resources', [TaglessCardCompanyResource::class]);

    Company::create(['name' => 'Acme']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/tagless-card-companies')
        ->assertOk()
        ->json('data');

    // The card's `tags` slot is bound to a path the model can never answer —
    // RecordSerializer's own `method_exists(tagsWithType)` gate skips its
    // tags-specific pass, leaving whatever the generic card-field pass wrote:
    // `data_get($record, 'tags')` on a model with neither a `tags` column
    // nor relation, which is null.
    expect($data)->toHaveCount(1)
        ->and($data[0]['tags'])->toBeNull();
});

it('lists a medialess-card resource without 500ing once a row exists', function () {
    config()->set('filament-mobile.resources', [MedialessCardResource::class]);

    Company::create(['name' => 'Acme']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/medialess-cards')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['photo'])->toBeNull();
});
