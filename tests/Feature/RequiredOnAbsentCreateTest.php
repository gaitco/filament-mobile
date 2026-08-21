<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GalleryResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RequiredMediaGalleryResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RequiredSpatieTagsArticleResource;
use Illuminate\Support\Facades\Storage;

/**
 * Final review, finding 2: `RecordForm::saveRelations()`'s `! Arr::has(
 * $payload, $name)` short-circuit skipped the tags/media branches entirely,
 * so a `->required()` Spatie tags OR media field left off the CREATE
 * payload never refused — mobile looser than web, which does refuse. Fixed
 * with a create-only absence check (`assertNotRequiredOnAbsentCreate()`)
 * before the `continue`. UPDATE stays untouched: absence there still means
 * "leave it alone", per the method's own pre-existing docblock.
 */
beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(config('filament.default_filesystem_disk'));

    config()->set('filament-mobile.resources', [
        ArticleResource::class,
        RequiredSpatieTagsArticleResource::class,
        GalleryResource::class,
        RequiredMediaGalleryResource::class,
    ]);
});

it('refuses a create whose payload omits a required spatie tags field entirely', function () {
    // The attribute save (`$model::create()`) and the relation pass
    // (`saveRelations()`, where this refusal lives) are two separate steps
    // in `store()` — a pre-existing, accepted ordering limitation (deferred
    // in the P15 final review as "apply-phase runtime failures
    // non-transactional") this test does not re-litigate. What it pins is
    // the 422 itself and that no tags ever synced onto whatever row exists.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/required-spatie-tags-articles', [
            'title' => 'A',
            // 'tags' entirely absent — not even `[]`.
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tags']);

    expect(Article::first()?->tags)->toBeEmpty();
});

it('still creates when an optional spatie tags field is absent from the payload', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/articles', ['title' => 'A'])
        ->assertCreated();

    $article = Article::find($response->json('data.id'));

    expect($article)->not->toBeNull()
        ->and($article->tags)->toBeEmpty();
});

it('round-trips tags on a create submission', function () {
    $data = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/articles', [
            'title' => 'A',
            'tags' => ['red', 'blue'],
        ])
        ->assertCreated()
        ->json('data');

    expect($data['tags'])->toEqualCanonicalizing(['red', 'blue']);

    $article = Article::find($data['id']);
    expect($article->tags->pluck('name')->all())->toEqualCanonicalizing(['red', 'blue']);
});

it('refuses a create whose payload omits a required media field entirely', function () {
    // Same pre-existing ordering caveat as the tags case above: the row may
    // exist by the time this 422 fires, but nothing was ever attached to it.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/required-media-galleries', [
            'name' => 'New',
            // 'cover' entirely absent — not even `''`.
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cover']);

    expect(Gallery::first()?->getMedia('cover'))->toHaveCount(0);
});

it('still creates when an optional media field is absent from the payload', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/galleries', ['name' => 'New'])
        ->assertCreated();

    $gallery = Gallery::find($response->json('data.id'));

    expect($gallery)->not->toBeNull()
        ->and($gallery->getMedia('photos'))->toHaveCount(0);
});
