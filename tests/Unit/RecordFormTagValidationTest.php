<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Write\RecordForm;
use Illuminate\Validation\ValidationException;

/**
 * Final review triage rider: a bare `$tag === ''` check let a whitespace-
 * only element (" ", "\t") reach Filament's own `findOrCreate()`, which
 * would mint a tag indistinguishable from "no tag" once trimmed for
 * display. Fixed with `trim($tag) === ''` in `RecordForm::saveRelations()`.
 *
 * Called directly rather than through an HTTP request: this test app's
 * global `TrimStrings`/`ConvertEmptyStringsToNull` middleware already
 * normalises a whitespace-only string to `null` before it reaches
 * `saveRelations()` over HTTP, which would exercise the pre-existing
 * `! is_string($tag)` branch instead of the new `trim()` one — masking
 * exactly the line this test exists to pin. A direct call is the only way
 * to hand `saveRelations()` the raw string this fix actually guards.
 */
it('refuses a whitespace-only tag element, bypassing HTTP middleware normalisation', function () {
    $article = Article::create(['title' => 'A']);
    $article->attachTags(['keep']);

    $payload = ['title' => 'A', 'tags' => ['fine', "  \t"]];

    expect(fn () => RecordForm::saveRelations(
        ArticleResource::class,
        $payload,
        $article,
        'edit',
        $payload,
    ))->toThrow(ValidationException::class);

    expect($article->fresh()->tags->pluck('name')->all())->toBe(['keep']);
});
