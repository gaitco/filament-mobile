<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\TagSeparators;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SeparatedSpatieTagsArticleResource;

/**
 * P15 Task 2 — load-bearing: `TagSeparators::in()` matches on
 * `ComponentTypeMap::for() === 'tags'`, and once `SpatieTagsInput` maps to
 * that same type a `->separator(',')` declared on one would otherwise be
 * imploded into a column that does not exist (a Spatie field is saved
 * through Filament's own relationship closure, never a `dehydrateStateUsing`
 * mirror). `in()` must skip `TagFields::isSpatieTags()` components
 * unconditionally, whatever `getSeparator()` answers.
 */
it('never mirrors a separator declared on a spatie tags field', function () {
    expect(TagSeparators::forResource(SeparatedSpatieTagsArticleResource::class))->toBe([]);
});
