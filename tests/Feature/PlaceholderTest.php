<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Gait\FilamentMobile\Write\RecordForm;
use Gait\FilamentMobile\Write\WritableNames;

/**
 * P10 Task 3: `Placeholder` maps to the existing `text_entry` type. It is a
 * deprecated alias extending Infolists' TextEntry (measured in vendor), so it
 * displays rather than saves: `Entry::isDehydrated()` is a hard `false`, which
 * is the exact refusal FieldPersistence already applies — no rule, no writable
 * name, no column, and zero new machinery on either side.
 */

it('publishes a placeholder as a text_entry node', function () {
    $node = findFormNode(schemaFor('banners'), 'delivery_note');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('text_entry')
        ->and($node['rules'])->toBe([]);
});

it('admits no rule and no writable name for a placeholder', function () {
    // The write path's own descent, not a re-derivation: if a rule ever named
    // `delivery_note`, the name would enter the mass-assignment whitelist and
    // a crafted value would reach create() — as a column that does not exist.
    $components = RecordForm::components(BannerResource::class, [], record: null);

    expect(RuleExtractor::fromComponents($components))->not->toHaveKey('delivery_note')
        ->and(WritableNames::of($components))->not->toContain('delivery_note');
});

it('drops a submitted placeholder value rather than writing it', function () {
    // `delivery_note` deliberately has NO column on the fixture table, so a
    // write that reached it would 500 on the unknown column. A 201 here is
    // the proof the key never survived validation — the same fail-closed
    // treatment every non-persisting field gets.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Created',
            'body_html' => '<p>Body</p>',
            'delivery_note' => 'crafted',
        ])
        ->assertCreated();
});
