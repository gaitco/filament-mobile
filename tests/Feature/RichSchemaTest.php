<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RichResource;
use Gait\MobileCore\WalkWarnings;

/**
 * P6e Task 2: the walker refines `text_entry` to `rich_entry` on either half
 * of Filament's own `isProse()` — an explicit `->prose()` call, or the
 * model declaring the attribute rich content (`RichContent::attributesFor()`,
 * Task 1). `isProse()`'s own second half calls `getRecord()`, and
 * `infolistPaths()` deliberately passes none (see its docblock), so that half
 * is answered against the model class instead — walk()'s new fourth param.
 *
 * RichResource's model is `Banner`, not an empty stand-in: `Banner`
 * genuinely registers `body_html` as rich content (Task 1, RichContentTest),
 * so `body_html` here exercises the model-declared half against real data,
 * and `name` — a column Banner does NOT register — proves the "leave it
 * alone" path runs against a model where rich detection actually works
 * rather than one where nothing was ever wired up.
 */
function richNode(string $name): array
{
    $components = RichResource::infolist(Schema::make())->getComponents();

    $nodes = (new SchemaWalker(new WalkWarnings()))->walk(
        $components,
        'RichResource',
        'rich-resource',
        RichResource::getModel(),
    );

    $node = collect($nodes)->firstWhere('name', $name);

    expect($node)->not->toBeNull();

    return $node;
}

it('refines an explicitly prose entry to rich_entry', function () {
    expect(richNode('prose_body')['type'])->toBe('rich_entry');
});

it('refines a model-declared rich attribute to rich_entry', function () {
    expect(richNode('body_html')['type'])->toBe('rich_entry');
});

it('leaves an ordinary text entry alone', function () {
    expect(richNode('name')['type'])->toBe('text_entry');
});

it('does not upgrade when the gate throws', function () {
    // Refusal here means NOT upgrading: today's behaviour, not a broken one.
    expect(richNode('exploding_body')['type'])->toBe('text_entry');
});

it('leaves every node text_entry when walk() is called with no model', function () {
    // The fourth parameter is optional so the many unit tests that walk bare
    // component lists with no model keep working untouched (design
    // constraint). A model-declared attribute must not upgrade when the
    // walker was never told which model to ask.
    $components = RichResource::infolist(Schema::make())->getComponents();

    $nodes = (new SchemaWalker(new WalkWarnings()))->walk($components, 'RichResource');

    expect(collect($nodes)->firstWhere('name', 'body_html')['type'])->toBe('text_entry');
});
