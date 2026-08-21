<?php

declare(strict_types=1);

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Infolists\Components\SpatieTagsEntry;
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

/**
 * The premise `TagWalkerTest`'s original docblock stated ("no infolist tags
 * entry equivalent") is what this task refutes: `SpatieTagsEntry` is mapped
 * to its own `tags_entry` type now (ComponentTypeMap), which — unlike
 * `SpatieTagsInput`'s `tags` mapping — needs no special case in
 * `walkNodes()` at all: it never fails closed the way a form field does
 * (there is no write path to protect), so it flows through the ordinary
 * entry node/config path exactly like `text_entry`/`image_entry` do. `config`
 * carries neither `separator` nor `suggestions` — those are the `tags`
 * branch's own keys, and `config()`'s default fallthrough for every other
 * entry type is `[]`.
 */
it('publishes a spatie tags entry as an ordinary tags_entry node, any and typed', function () {
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        SpatieTagsEntry::make('tags'),
        SpatieTagsEntry::make('topics')->type('topics'),
    ], 'ArticleResource', 'articles', Article::class);

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]['type'])->toBe('tags_entry')
        ->and($nodes[0]['name'])->toBe('tags')
        ->and($nodes[0]['label'])->toBe('Tags')
        // An empty `config` is never published (node()'s `$config !== []`
        // gate) — the same absence every other config-less entry
        // (text_entry, image_entry) gets.
        ->and($nodes[0])->not->toHaveKey('config')
        ->and($nodes[1]['type'])->toBe('tags_entry')
        ->and($nodes[1]['name'])->toBe('topics')
        ->and($nodes[1]['label'])->toBe('Topics')
        ->and($nodes[1])->not->toHaveKey('config');
});

/**
 * Unlike a `SpatieTagsInput`, a `SpatieTagsEntry` on a model without HasTags
 * is NOT dropped: `walkNodes()`'s fail-closed branch is gated on
 * `$type === 'tags'`, which a `tags_entry` node never is. There is no write
 * path to protect on a read-only entry, and the entry's own `getState()`
 * degrades to an empty list gracefully (measured in vendor) — the same
 * graceful-empty ruling `RecordSerializer::withTagPaths()`'s
 * `method_exists($record, 'tagsWithType')` gate already applies to the
 * record-bound read.
 */
it('does not drop a spatie tags entry on a model without HasTags', function () {
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk([
        SpatieTagsEntry::make('tags'),
    ], 'CompanyResource', 'companies', Company::class);

    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]['type'])->toBe('tags_entry')
        ->and($warnings->all())->toBeEmpty();
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
