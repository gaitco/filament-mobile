<?php

declare(strict_types=1);

use Filament\Forms\Components\SpatieTagsInput;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Tests\Fixtures\Models\Article;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\MobileCore\WalkWarnings;

/**
 * P15 Task 2: the walker maps `SpatieTagsInput` onto the existing `tags`
 * node — no new type, no new wire shape — and fails closed by DROPPING the
 * field with a warning, unlike media's `readOnly` ruling: no client honours
 * `readOnly` on a `tags` node, so a control the write path would certainly
 * refuse must not be drawn at all (design spec).
 *
 * `config` carries no `separator` key at all for either field (Task 4's
 * rider fix) — see the test below for the case that actually pins this
 * against a DECLARED separator, which this fixture never sets.
 */
it('publishes both articles fields as editable tags nodes with no separator', function () {
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        SpatieTagsInput::make('tags'),
        SpatieTagsInput::make('topics')->type('topics'),
    ], 'ArticleResource', 'articles', Article::class);

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]['type'])->toBe('tags')
        ->and($nodes[0]['name'])->toBe('tags')
        ->and($nodes[0]['disabled'])->toBeFalse()
        ->and($nodes[0])->not->toHaveKey('writable')
        ->and($nodes[0]['config'])->not->toHaveKey('separator')
        ->and($nodes[1]['type'])->toBe('tags')
        ->and($nodes[1]['name'])->toBe('topics')
        ->and($nodes[1]['disabled'])->toBeFalse()
        ->and($nodes[1]['config'])->not->toHaveKey('separator');
});

/**
 * P15 Task 4 rider: `getSeparator()` still resolves for a Spatie field
 * (nothing on the plugin side suppresses it), but the walker must not
 * publish that answer at all — `TagSeparators::in()` never honours it, so a
 * client reading `separator: ','` would be shown a lie about how the write
 * path splits the value (it does not: the value is always a List<String>,
 * synced wholesale).
 */
it('omits the separator key entirely for a spatie tags field that declares one', function () {
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        SpatieTagsInput::make('x')->separator(','),
    ], 'ArticleResource', 'articles');

    expect($nodes[0]['config'])->not->toHaveKey('separator')
        ->and($nodes[0]['config']['suggestions'])->toBe([]);
});

it('drops a spatie tags field on a model without HasTags, with a warning naming it', function () {
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk([
        SpatieTagsInput::make('tags'),
    ], 'CompanyResource', 'companies', Company::class);

    expect($nodes)->toBeEmpty()
        ->and($warnings->all())->toHaveCount(1)
        ->and($warnings->all()[0]['component'])->toBe('tags')
        ->and($warnings->all()[0]['reason'])->toContain('HasTags');
});

it('drops a spatie tags field whose type() closure throws, with a warning naming it', function () {
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk([
        SpatieTagsInput::make('tags')->type(fn () => throw new RuntimeException('boom')),
    ], 'ArticleResource', 'articles', Article::class);

    expect($nodes)->toBeEmpty()
        ->and($warnings->all())->toHaveCount(1)
        ->and($warnings->all()[0]['component'])->toBe('tags');
});
