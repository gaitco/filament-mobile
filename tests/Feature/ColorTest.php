<?php

declare(strict_types=1);

use Filament\Forms\Components\ColorPicker;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\WalkWarnings;

/**
 * Task 3 of P8. `ColorPicker` exposes exactly one accessor, `getFormat()`
 * (measured in vendor/filament/forms/src/Components/ColorPicker.php) —
 * `'hex'` by default, `'hsl'`/`'rgb'`/`'rgba'` via the matching
 * `->hsl()`/`->rgb()`/`->rgba()` helper. This file covers only what the
 * walker publishes about the FORMAT; the wire *value* is a plain string in
 * that format and travels unmodified through the ordinary default/state
 * paths every other field already uses — there is nothing colour-specific
 * for the walker to do with it.
 *
 * Four fixtures, one per format, because a single-format fixture cannot show
 * that the format is READ rather than assumed — the same reasoning
 * DateConfigTest and TimeTest give for their bounded/unbounded pairs.
 */
function colorNode(string $name): array
{
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        ColorPicker::make('hex_color'),
        ColorPicker::make('hsl_color')->hsl(),
        ColorPicker::make('rgb_color')->rgb(),
        ColorPicker::make('rgba_color')->rgba(),
        // A value outside the closed set — not a Filament possibility via
        // ->hsl()/->rgb()/->rgba(), but ->format() itself takes a bare
        // string|Closure, so nothing stops a host override (or a future
        // Filament version) from producing one.
        ColorPicker::make('nonsense_color')->format('sideways'),
        ColorPicker::make('exploding_color')->format(fn () => throw new RuntimeException('boom')),
    ], 'TestResource');

    foreach ($nodes as $node) {
        if ($node['name'] === $name) {
            return $node;
        }
    }

    throw new RuntimeException("no node named {$name}");
}

it('publishes a color picker as its own type', function () {
    expect(colorNode('hex_color')['type'])->toBe('color');
});

it('publishes the hex format, filament\'s own default', function () {
    expect(colorNode('hex_color')['config'])->toBe(['format' => 'hex']);
});

it('publishes the hsl format', function () {
    expect(colorNode('hsl_color')['config'])->toBe(['format' => 'hsl']);
});

it('publishes the rgb format', function () {
    expect(colorNode('rgb_color')['config'])->toBe(['format' => 'rgb']);
});

it('publishes the rgba format', function () {
    expect(colorNode('rgba_color')['config'])->toBe(['format' => 'rgba']);
});

it('normalises a nonsense format override to hex', function () {
    // The closed set: a client cannot act on a fifth value, and hex is
    // Filament's own default. Mirrors PanelDirectionTest's identical rule for
    // `filament-panels::layout.direction`.
    expect(colorNode('nonsense_color')['config'])->toBe(['format' => 'hex']);
});

it('degrades a throwing format closure to hex, not a failed document', function () {
    expect(colorNode('exploding_color')['config'])->toBe(['format' => 'hex']);
});
