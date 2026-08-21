<?php

declare(strict_types=1);

use Filament\Forms\Components\Slider;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\MobileCore\WalkWarnings;

/**
 * P10 Task 2: `Slider` becomes a mapped type, `slider` on the wire.
 *
 * `config.min`/`config.max`/`config.multiple` are always present; `step` only
 * when it answers an int or float (a string or null step means "any step",
 * never an error). `multiple` is isMultiple() — which reads the RAW STATE
 * (measured in vendor: `is_array($this->getRawState())`) — with the array
 * default as the detectable signal on the empty /schema snapshot, where no
 * state is seeded at all.
 *
 * The bounds the field force-registers in setUp() (required, numeric, min:/max:,
 * integer/multiple_of: — measured in vendor) are published and enforced through
 * the rule machinery; a range slider's per-element rules arrive through
 * Filament's own HasNestedRecursiveValidationRules, the same interface P7's
 * tags handling already reads.
 */

function sliderNode(string $name): array
{
    return findFormNode(schemaFor('banners'), $name);
}

/** The two fields every banner write must carry to clear validation. */
function sliderWritePayload(array $extra): array
{
    return ['name' => 'Banner', 'body_html' => '<p>Body</p>', ...$extra];
}

it('publishes a single slider with min/max/step/multiple config', function () {
    $node = sliderNode('rating');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('slider')
        ->and($node['config']['min'])->toBe(0)
        ->and($node['config']['max'])->toBe(10)
        ->and($node['config']['step'])->toBe(1)
        ->and($node['config']['multiple'])->toBeFalse()
        // Slider::setUp() defaults the state to the minimum (measured in
        // vendor), so the wire default is 0 whether the panel asked or not.
        ->and($node['default'])->toBe(0);
});

it('publishes the field own bounds as rules hints', function () {
    $node = sliderNode('rating');

    // The fixture relaxes the force-registered `required` (it would 422 every
    // pre-existing create test on this resource); the bounds are the field's
    // own and the hint must match what the write path enforces.
    expect($node['rules']['numeric'])->toBeTrue()
        ->and($node['rules']['min'])->toBe(0)
        ->and($node['rules']['max'])->toBe(10);
});

it('publishes required for a slider that did not relax it', function () {
    // Slider::setUp() calls required() unconditionally, so an unrelaxed
    // slider publishes it like any other required field. Bare components are
    // enough: isRequired is a plain property, no container involved.
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        Slider::make('rating')->range(0, 10)->step(1),
    ], 'TestResource');

    expect($nodes[0]['rules'])->toMatchArray([
        'required' => true,
        'numeric' => true,
        'min' => 0,
        'max' => 10,
    ]);
});

it('publishes a range slider as multiple: true, detected from its array default', function () {
    $node = sliderNode('price_range');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('slider')
        ->and($node['config']['multiple'])->toBeTrue()
        ->and($node['config']['min'])->toBe(0)
        ->and($node['config']['max'])->toBe(100)
        ->and($node['config']['step'])->toBe(5)
        ->and($node['default'])->toBe([20, 40]);
});

it('publishes no step key for a string or absent step', function () {
    // getStep() answers int|float|string|null (measured in vendor). A string
    // or null step means "any step" — absence of the key, never an error.
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        Slider::make('pace')->step('any'),
        Slider::make('free'),
    ], 'TestResource');

    expect($nodes[0]['config'])->not->toHaveKey('step')
        ->and($nodes[1]['config'])->not->toHaveKey('step')
        // …while min/max/multiple are always present regardless.
        ->and($nodes[1]['config']['min'])->toBe(0)
        ->and($nodes[1]['config']['max'])->toBe(100)
        ->and($nodes[1]['config']['multiple'])->toBeFalse();
});

it('round-trips a single slider value through the write path', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", sliderWritePayload([
            'rating' => 7,
        ]))
        ->assertOk();

    expect($banner->fresh()->rating)->toBe(7);
});

it('422s an out-of-range single value, keyed to the field', function () {
    $banner = seedBanner();

    // The field's own force-registered max:10, enforced through the ordinary
    // rule machinery — the write path must never be looser than the web panel.
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", sliderWritePayload([
            'rating' => 50,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rating']);
});

it('round-trips a range slider as a two-element list', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", sliderWritePayload([
            'price_range' => [10, 60],
        ]))
        ->assertOk();

    expect($banner->fresh()->price_range)->toBe([10, 60]);
});

it('422s an out-of-range range element, keyed to the field', function () {
    $banner = seedBanner();

    // The range shape's per-element min:/max: arrive through Filament's own
    // nested-recursive rules — the same interface a tags field's per-tag rules
    // already travel by — so the 422 keys to the offending element.
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", sliderWritePayload([
            'price_range' => [20, 500],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price_range.1']);
});
