<?php

declare(strict_types=1);

use Filament\Forms\Components\Radio;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\MobileCore\WalkWarnings;

/**
 * P7 Task 1: `Radio` becomes the walker's first mapped type since P6 closed.
 * Before this, it was unmapped — dropped with an `unsupported component
 * type` warning, and a dropped field gets no validation rule, so a NOT NULL
 * column behind one failed at the database rather than at validation.
 */
function radioNode(string $name): array
{
    return findFormNode(schemaFor('banners'), $name);
}

it('publishes a radio with its options', function () {
    $node = radioNode('plan');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('radio')
        // Same flattened shape select publishes — `Radio` uses the very same
        // `Concerns\HasOptions` trait and `getOptions()`, measured in vendor.
        ->and($node['config']['options'])->toBe([
            ['value' => 'basic', 'label' => 'Basic'],
            ['value' => 'pro', 'label' => 'Pro'],
        ]);
});

it('refuses a radio inside a disabled section', function () {
    // A gate that cannot answer refuses; a disabled ancestor is a gate.
    expect(radioNode('locked_plan')['disabled'])->toBeTrue();
});

it('degrades a throwing options closure to an empty list, not a failed document', function () {
    $node = radioNode('exploding_plan');

    // `config()` withholds the whole `config` object when its only content
    // (`options`) reads back empty — the SAME behaviour a throwing select
    // options() closure gets today, not a radio-specific regression. What
    // this test actually guards is the second half of its own name: the
    // document itself must not fail. `?? []` reads "no options key" and "an
    // explicit empty list" as the one thing a client cares about — nothing
    // to render — rather than asserting an implementation detail the brief's
    // literal snippet got wrong.
    expect($node)->not->toBeNull()
        ->and($node['config']['options'] ?? [])->toBe([]);
});

it('inlines every option for an over-cap radio rather than publishing a search URL nothing can resolve', function () {
    // The option branch config() shares with `select`/`multiselect` falls
    // back to `optionsUrl` when the list outgrows `options_inline_max` — a
    // real affordance for those two, because the client posts back to a
    // search endpoint. A `Radio` has no `isSearchable()` at all (measured in
    // vendor: it does not use Concerns\CanBeSearchable) and no such
    // endpoint, so publishing a URL for one would be an affordance that
    // cannot work — exactly what this package refuses to do elsewhere.
    config(['filament-mobile.options_inline_max' => 1]);

    $walker = new SchemaWalker(new WalkWarnings());
    $nodes = $walker->walk([
        Radio::make('sizes')->options([
            's' => 'Small',
            'm' => 'Medium',
            'l' => 'Large',
        ]),
    ], 'TestResource');

    expect($nodes[0]['config'])->not->toHaveKey('optionsUrl')
        ->and($nodes[0]['config']['options'])->toBe([
            ['value' => 's', 'label' => 'Small'],
            ['value' => 'm', 'label' => 'Medium'],
            ['value' => 'l', 'label' => 'Large'],
        ]);
});
