<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TaglessFormBannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ThrowingTypeArticleResource;

/**
 * Final review, finding 3: `MobilePanelController::formProjection()` walked
 * the edit form WITHOUT the record's model class, unlike `infolistPaths()`
 * beside it — the one argument `SchemaWalker::tagsFailClosed()` needs to
 * drop a Spatie tags field on a model without `HasTags`. Fixed by passing
 * `$class::getModel()` at the same call site.
 */
it('leaks nothing for a spatie tags field on a model without HasTags whose name collides with a real relation', function () {
    config()->set('filament-mobile.resources', [TaglessFormBannerResource::class]);

    $banner = seedBanner();
    $banner->tags()->attach(Tag::create(['name' => 'plain-tag']));

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/tagless-form-banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    // Before the fix this key held Banner's real `tags()` relation — a list
    // of whole `Tag` models with pivot rows — because the walker (model-less)
    // never dropped the field and RecordSerializer's generic form-field pass
    // read the name straight off the record.
    expect($data)->not->toHaveKey('tags');
});

it('still drops a spatie tags field whose type() closure throws from the edit-form projection', function () {
    config()->set('filament-mobile.resources', [ThrowingTypeArticleResource::class]);

    $article = Article::create(['title' => 'A']);
    $article->attachTags(['red']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/throwing-type-articles/{$article->id}")
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('tags');
});
