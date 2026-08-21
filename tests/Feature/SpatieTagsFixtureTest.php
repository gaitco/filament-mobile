<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Illuminate\Support\Facades\DB;

it('attaches spatie tags to the article fixture on the remapped table', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['alpha', 'beta']);

    expect($article->tags->pluck('name')->all())->toBe(['alpha', 'beta'])
        ->and(DB::table('spatie_tags')->count())->toBe(2)
        ->and(DB::table('tags')->count())->toBe(0);
});

it('publishes the articles resource on /schema', function () {
    // Per-test only (P14's golden-isolation rule) — registering `articles`
    // in the shared TestCase list would change ContractSnapshotTest's
    // ResourceRegistry() output for every other test in the suite.
    config()->set('filament-mobile.resources', [ArticleResource::class]);

    $response = $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/schema');

    $resource = collect($response->json('resources'))->firstWhere('key', 'articles');

    expect($resource)->not->toBeNull();
});
