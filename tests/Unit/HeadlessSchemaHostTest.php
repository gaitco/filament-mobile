<?php

declare(strict_types=1);

use Filament\Schemas\Contracts\HasSchemas;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;

it('satisfies the contract Filament schemas require', function () {
    expect(new HeadlessSchemaHost())->toBeInstanceOf(HasSchemas::class);
});

it('lets a reactive closure read submitted state through Get', function () {
    $host = new HeadlessSchemaHost();
    $host->setMobileState(['country_id' => 3]);

    $schema = Filament\Schemas\Schema::make($host)->components([
        Filament\Forms\Components\Select::make('country_id'),
        Filament\Forms\Components\Select::make('city_id')->visible(
            fn (Filament\Schemas\Components\Utilities\Get $get) => $get('country_id') === 3,
        ),
    ]);

    $components = $schema->getComponents();

    expect($components[1]->isVisible())->toBeTrue();
});

it('hides a field whose condition the submitted state does not meet', function () {
    $host = new HeadlessSchemaHost();
    $host->setMobileState(['country_id' => 99]);

    $schema = Filament\Schemas\Schema::make($host)->components([
        Filament\Forms\Components\Select::make('country_id'),
        Filament\Forms\Components\Select::make('city_id')->visible(
            fn (Filament\Schemas\Components\Utilities\Get $get) => $get('country_id') === 3,
        ),
    ]);

    // `getComponents()` drops hidden components, so the field being absent
    // there is itself the assertion; `withHidden` gets it back to confirm the
    // closure — not a missing component — is what hid it.
    //
    // The state round-trip is what makes this test discriminating: hiding alone
    // is equally consistent with no state at all, since `null === 3` is also
    // false. Asserting the 99 came back proves the seed reached the host.
    expect($schema->getComponents(withHidden: true)[0]->getState())->toBe(99)
        ->and($schema->getComponents())->toHaveCount(1)
        ->and($schema->getComponents(withHidden: true)[1]->isVisible())->toBeFalse();
});
