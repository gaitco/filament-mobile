<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardedTagsArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\InfolistOnlyTagsArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SecretResource;
use Illuminate\Support\Facades\DB;

// Per-test, not the shared TestCase list — ArticleResource's own docblock and
// MediaFixtureTest's precedent: adding `articles` to the default list would
// change ContractSnapshotTest's golden, whose job is "server without tags".
// The default list's other three resources are carried over so `/banners/1`
// (used below for the traitless-model assertion) still resolves.
beforeEach(function (): void {
    config()->set('filament-mobile.resources', [
        PostResource::class,
        BannerResource::class,
        SecretResource::class,
        ArticleResource::class,
        CardedTagsArticleResource::class,
        InfolistOnlyTagsArticleResource::class,
    ]);
});

it('publishes tag names on the record endpoint, scoped by type', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['red', 'blue']);
    $article->attachTags(['sports'], 'topics');

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/articles/{$article->id}")
        ->assertOk()
        ->json('data');

    // `tags` is the any-type field: every attached tag, regardless of type.
    expect($data['tags'])->toEqualCanonicalizing(['red', 'blue', 'sports'])
        // `topics` is scoped to type `topics` only — the untyped `red`/`blue`
        // pair must not leak in.
        ->and($data['topics'])->toBe(['sports']);
});

it('publishes an empty list for a tagless record and absence for a traitless model', function () {
    $article = Article::create(['title' => 'Tagless']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/articles/{$article->id}")
        ->json('data');

    expect($data['tags'])->toBe([])
        ->and($data['topics'])->toBe([]);

    // A resource whose model lacks HasTags never grows a tags key: any
    // existing non-tags record payload proves it.
    $banner = seedBanner();
    $bannerData = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->json('data');

    expect($bannerData)->not->toHaveKey('tags')
        ->and($bannerData)->not->toHaveKey('topics');
});

it('reflects post-sync tag names on the write response', function () {
    $article = Article::create(['title' => 'Before']);
    $article->attachTags(['alpha', 'beta']);

    $data = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", ['title' => 'After'])
        ->assertOk()
        ->json('data');

    expect($data['title'])->toBe('After')
        ->and($data['tags'])->toEqualCanonicalizing(['alpha', 'beta']);
});

it('carries tags on list rows only when the card binds the field', function () {
    $article = Article::create(['title' => 'Listed']);
    $article->attachTags(['one', 'two']);

    $cardedRow = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/carded-tags-articles')
            ->assertOk()
            ->json('data'),
    )->firstWhere('id', $article->id);

    expect($cardedRow['tags'])->toEqualCanonicalizing(['one', 'two']);

    // `articles`' own card binds only `title` — its list rows must NOT carry
    // `tags` at all (the card whitelist rule).
    $plainRow = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/articles')
            ->assertOk()
            ->json('data'),
    )->firstWhere('id', $article->id);

    expect($plainRow)->not->toHaveKey('tags');
});

it('publishes a tags_entry path the form never declares — proves the infolist fold', function () {
    // InfolistOnlyTagsArticleResource declares `curated` on its INFOLIST
    // alone, never on the form. Before MobilePanelController::show()'s
    // formProjection()-plus-infolist fold, `tagPaths` was built from the
    // form's components only, so this path walked and published on
    // `/schema` but never appeared on a record payload at all.
    $article = Article::create(['title' => 'Curated']);
    $article->attachTags(['pick', 'of', 'the', 'week'], 'curated');

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/infolist-only-tags-articles/{$article->id}")
        ->assertOk()
        ->json('data');

    expect($data['curated'])->toEqualCanonicalizing(['pick', 'of', 'the', 'week']);
});

it('eager loads tags so the card-bound field does not N+1 on the list endpoint', function () {
    for ($i = 0; $i < 20; $i++) {
        $article = Article::create(['title' => "Article {$i}"]);
        $article->attachTags(['common']);
    }

    DB::enableQueryLog();
    $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/carded-tags-articles')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Without the eager load this is 20+ queries (one `tags` lookup per row).
    expect($queries)->toBeLessThan(10);
});
