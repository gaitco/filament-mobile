<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RequiredSpatieTagsArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TaglessCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ThrowingTypeArticleResource;

/**
 * P15 Task 4: the write branch in `RecordForm::saveRelations()` — see its
 * docblock and `MediaReconciler`'s enforcement style, which this mirrors.
 */
beforeEach(function (): void {
    config()->set('filament-mobile.resources', [
        ArticleResource::class,
        RequiredSpatieTagsArticleResource::class,
        TaglessCompanyResource::class,
    ]);
});

it('syncs the any-type tags field wholesale on update', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['old']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['new', 'fresh'],
        ])
        ->assertOk();

    expect($article->fresh()->tags->pluck('name')->all())->toEqualCanonicalizing(['new', 'fresh']);
});

it('scopes a typed field write to its own type in spatie_tags', function () {
    $article = Article::create(['title' => 'A']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'topics' => ['sports', 'news'],
        ])
        ->assertOk();

    expect($article->fresh()->tagsWithType('topics')->pluck('name')->all())
        ->toEqualCanonicalizing(['sports', 'news'])
        // The any-type field was never mentioned in the payload, so ITS OWN
        // scope (untyped, `type === null`) stays empty — proves the typed
        // sync landed under `topics` and not under no type at all. `tags`
        // (unscoped) legitimately still holds them: it is every tag on the
        // record regardless of type.
        ->and($article->fresh()->tagsWithType(null)->pluck('name')->all())->toBe([]);
});

it('clears every tag when the submitted list is empty', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['one', 'two']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => [],
        ])
        ->assertOk();

    expect($article->fresh()->tags->pluck('name')->all())->toBe([]);
});

it('leaves tags untouched when the field is absent from the payload', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'Renamed',
        ])
        ->assertOk();

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

it('leaves tags untouched when the field is present but null', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => null,
        ])
        ->assertOk();

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

it('refuses a non-string element with a field-keyed 422 and syncs nothing', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['fine', ['nested' => 'x']],
        ])
        ->assertJsonValidationErrors(['tags']);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

it('refuses a non-list (keyed map) value with a field-keyed 422 and syncs nothing', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['a' => 'one', 'b' => 'two'],
        ])
        ->assertJsonValidationErrors(['tags']);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

it('refuses an empty-string element with a field-keyed 422 and syncs nothing', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['fine', ''],
        ])
        ->assertJsonValidationErrors(['tags']);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

/**
 * Final review triage rider: a bare `=== ''` check let a whitespace-only
 * element (" ") through to Filament's own `findOrCreate()`, which would
 * mint a tag indistinguishable from "no tag" once trimmed for display.
 */
it('refuses a whitespace-only element with a field-keyed 422 and syncs nothing', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['fine', "  \t"],
        ])
        ->assertJsonValidationErrors(['tags']);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

/**
 * Task review finding: the tags branch used to sync field-by-field inside
 * the loop, so `{"tags": ["a"], "topics": ["x", 5]}` on `ArticleResource`
 * (which has both fields) wrote `tags` and THEN 422'd on `topics` — a
 * persisted change behind an error response. Fixed the same way the media
 * branch already was: every tags field validates clean (collected into
 * `$tagComponents`) before any of them calls `saveRelationships()`.
 */
it('validates every tags field before syncing any of them — no half-applied write', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['baseline']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['a'],
            'topics' => ['x', 5],
        ])
        ->assertJsonValidationErrors(['topics']);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['baseline']);
});

it('refuses an empty list on a required field with a field-keyed 422', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson('/api/mobile-panel/required-spatie-tags-articles/' . $article->id, [
            'title' => 'A',
            'tags' => [],
        ])
        ->assertJsonValidationErrors(['tags']);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});

it('carries the freshly synced names on the write response, not a stale relation', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['old']);

    $data = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['new'],
        ])
        ->assertOk()
        ->json('data');

    expect($data['tags'])->toBe(['new']);
});

/**
 * Carried requirement from Task 2's review, HARD: `RuleExtractor` walks
 * components independently of `SchemaWalker`, so a Spatie tags field the
 * walker DROPPED (a traitless model) still has its name admitted through
 * `WritableNames` and reaches `RecordForm::saveRelations()`'s loop on a
 * crafted request. The branch's own two-gate check
 * (`! method_exists($record, 'syncTagsWithType') || TagFields::typeOf(...)
 * === null`) must `continue` BEFORE any sync — this pins that nothing
 * throws and nothing is persisted for that name.
 */
it('ignores a crafted tags value for a spatie field on a model without HasTags', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/tagless-companies', [
            'name' => 'Acme',
            'tags' => ['crafted'],
        ])
        ->assertCreated();

    // Nothing thrown (assertCreated already proves that), and nothing
    // written: Company has no tags relation at all to hold `crafted`, and
    // this asserts the write path did not, say, throw AND get swallowed
    // into a 500-turned-200 — the row exists with only the plain column.
    expect(\Gait\FilamentMobile\Tests\Fixtures\Models\Company::query()->where('name', 'Acme')->sole()->getAttributes())
        ->not->toHaveKey('tags');
});

/**
 * Final review triage rider: the OTHER half of the two-gate check above —
 * `TagFields::typeOf($component) === null` — on a model that DOES have
 * `HasTags` (`Article`). A throwing `->type()` closure must skip the field
 * silently on write too, the same way the walker drops it from `/schema`:
 * a crafted request naming it gets a plain 2xx, and nothing syncs.
 */
it('ignores a crafted tags value for a spatie field whose type() closure throws', function () {
    config()->set('filament-mobile.resources', [ThrowingTypeArticleResource::class]);

    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/throwing-type-articles/{$article->id}", [
            'title' => 'A',
            'tags' => ['crafted'],
        ])
        ->assertOk();

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});
