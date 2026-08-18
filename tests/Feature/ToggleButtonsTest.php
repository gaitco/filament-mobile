<?php

declare(strict_types=1);

use Filament\Forms\Components\ToggleButtons;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\WalkWarnings;

/**
 * P10 Task 1: `ToggleButtons` becomes a mapped type, `toggle_buttons` on the
 * wire. Before this it was unmapped — dropped with an `unsupported component
 * type` warning, and a dropped field gets no validation rule, so a NOT NULL
 * column behind one failed at the database rather than at validation.
 *
 * The type shares `Concerns\HasOptions` and `getOptions()` with Select and
 * Radio (measured in vendor), so the walker's existing option branch reads it
 * — widened like `radio` was, including the radio rule that it never gets an
 * `optionsUrl`: the control has no search affordance to post a query to.
 */

function toggleButtonsNode(string $name): array
{
    return findFormNode(schemaFor('banners'), $name);
}

/** The two fields every banner write must carry to clear validation. */
function toggleButtonsWritePayload(array $extra): array
{
    return ['name' => 'Banner', 'body_html' => '<p>Body</p>', ...$extra];
}

it('publishes a single toggle_buttons with flattened options and multiple always present', function () {
    $node = toggleButtonsNode('toggle_status');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('toggle_buttons')
        // Same flattened shape select and radio publish — one option branch,
        // widened rather than copied.
        ->and($node['config']['options'])->toBe([
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'live', 'label' => 'Live'],
        ])
        // A stated gate, like a repeater's readOnly: absent is never allowed
        // to mean "guess". Single is a scalar on the wire.
        ->and($node['config']['multiple'])->toBeFalse()
        ->and($node['config'])->not->toHaveKey('optionsUrl');
});

it('publishes a multiple toggle_buttons as multiple: true', function () {
    $node = toggleButtonsNode('toggle_flags');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('toggle_buttons')
        ->and($node['config']['multiple'])->toBeTrue()
        ->and($node['config']['options'])->toBe([
            ['value' => 'featured', 'label' => 'Featured'],
            ['value' => 'pinned', 'label' => 'Pinned'],
        ]);
});

it('inlines every option for an over-cap toggle_buttons rather than publishing a search URL nothing can resolve', function () {
    // The radio rule, extended: a ToggleButtons has no isSearchable() and no
    // options endpoint, so an over-cap field inlines its full list and the
    // client renders an arbitrarily long one — the only list this package can
    // honestly offer on a control with no search box.
    config(['filament-mobile.options_inline_max' => 1]);

    $walker = new SchemaWalker(new WalkWarnings());
    $nodes = $walker->walk([
        ToggleButtons::make('sizes')->options([
            's' => 'Small',
            'm' => 'Medium',
            'l' => 'Large',
        ]),
    ], 'TestResource');

    expect($nodes[0]['type'])->toBe('toggle_buttons')
        ->and($nodes[0]['config'])->not->toHaveKey('optionsUrl')
        // `multiple` survives even the inline fallback: it is always present,
        // never collateral of an empty or over-cap option list.
        ->and($nodes[0]['config']['multiple'])->toBeFalse()
        ->and($nodes[0]['config']['options'])->toBe([
            ['value' => 's', 'label' => 'Small'],
            ['value' => 'm', 'label' => 'Medium'],
            ['value' => 'l', 'label' => 'Large'],
        ]);
});

it('publishes the boolean() preset as options 1/0', function () {
    $node = toggleButtonsNode('toggle_active');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('toggle_buttons')
        ->and($node['config']['multiple'])->toBeFalse()
        ->and($node['config']['options'])->toBe([
            ['value' => 1, 'label' => 'Yes'],
            ['value' => 0, 'label' => 'No'],
        ]);
});

it('round-trips a single value through the write path', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", toggleButtonsWritePayload([
            'toggle_status' => 'live',
        ]))
        ->assertOk();

    expect($banner->fresh()->toggle_status)->toBe('live');
});

it('round-trips a multiple value as a list', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", toggleButtonsWritePayload([
            'toggle_flags' => ['featured', 'pinned'],
        ]))
        ->assertOk();

    expect($banner->fresh()->toggle_flags)->toBe(['featured', 'pinned']);
});

it('round-trips a boolean() value as the declared 1/0', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", toggleButtonsWritePayload([
            'toggle_active' => 1,
        ]))
        ->assertOk();

    $this->assertDatabaseHas('banners', [
        'id' => $banner->id,
        'toggle_active' => 1,
    ]);
});
