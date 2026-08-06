<?php

declare(strict_types=1);

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Gait\FilamentMobile\Introspection\ComponentTypeMap;

it('maps the field components the pilot panel actually uses', function () {
    expect(ComponentTypeMap::for(TextInput::make('a')))->toBe('text')
        ->and(ComponentTypeMap::for(Textarea::make('a')))->toBe('textarea')
        ->and(ComponentTypeMap::for(Select::make('a')))->toBe('select')
        ->and(ComponentTypeMap::for(Toggle::make('a')))->toBe('toggle')
        ->and(ComponentTypeMap::for(DatePicker::make('a')))->toBe('date');
});

it('maps a CheckboxList straight to multiselect', function () {
    // The pilot's single largest gap: 38 uses, 39% of every warning that
    // panel produced, and a core Filament field rather than a plugin. It is
    // always multi-valued and has no isMultiple(), so it maps to `multiselect`
    // directly rather than to `select` refined by the walker.
    expect(ComponentTypeMap::for(CheckboxList::make('a')))->toBe('multiselect');
});

it('maps layout components', function () {
    expect(ComponentTypeMap::for(Section::make()))->toBe('section')
        ->and(ComponentTypeMap::for(Grid::make()))->toBe('grid')
        ->and(ComponentTypeMap::for(Tabs::make('t')))->toBe('tabs');
});

it('flattens a Tab into a section', function () {
    // Unmapped, a Tab is dropped as unsupported and every tabbed form on the
    // pilot panel (45 Tab::make() calls) comes out empty. It is a labelled
    // container, so `section` carries it with no new contract type — and tabs
    // are the wrong control on a phone regardless.
    expect(ComponentTypeMap::for(Tab::make('Details')))->toBe('section');
});

it('maps an icon entry onto boolean_entry, a type the contract actually has', function () {
    // `icon_entry` was never in the frozen vocabulary (design spec §5.3), so
    // the Dart parser degraded every one to UnknownComponent — a debug
    // placeholder skipped entirely in release builds. Silently, on 18 entries
    // in the pilot panel.
    expect(ComponentTypeMap::for(TextEntry::make('a')))->toBe('text_entry')
        ->and(ComponentTypeMap::for(IconEntry::make('a')))->toBe('boolean_entry');
});

it('maps a Spatie media image entry onto image_entry by class name', function () {
    // Matched by name, not instanceof, so this package never has to depend on
    // the Spatie plugin to support a panel that uses it.
    expect(ComponentTypeMap::forClassName(
        'Filament\\Infolists\\Components\\SpatieMediaLibraryImageEntry',
    ))->toBe('image_entry')
        ->and(ComponentTypeMap::forClassName(
            'Filament\\Infolists\\Components\\ImageEntry',
        ))->toBe('image_entry');
});

it('skips Hidden without warning', function () {
    expect(ComponentTypeMap::isSkipped(Hidden::make('a')))->toBeTrue()
        ->and(ComponentTypeMap::isSkipped(TextInput::make('a')))->toBeFalse();
});

it('returns null for an unsupported component', function () {
    expect(ComponentTypeMap::forClassName('Filament\\Schemas\\Components\\Livewire'))
        ->toBeNull();
});

it('maps file uploads to the read-only file type', function () {
    expect(Gait\FilamentMobile\Introspection\ComponentTypeMap::forClassName(
        'Filament\\Forms\\Components\\FileUpload',
    ))->toBe('file')
        ->and(Gait\FilamentMobile\Introspection\ComponentTypeMap::forClassName(
            'Filament\\Forms\\Components\\SpatieMediaLibraryFileUpload',
        ))->toBe('file');
});

it('maps a component the host declared', function () {
    config(['filament-mobile.types' => ['App\\Vendor\\PhoneInput' => 'text']]);

    expect(ComponentTypeMap::forClassName('App\\Vendor\\PhoneInput'))->toBe('text');
});

it('lets a host override a built-in mapping', function () {
    // A panel that wants its Textarea rendered as a plain text field should not
    // have to fork the package to say so.
    config(['filament-mobile.types' => ['Filament\\Forms\\Components\\Textarea' => 'text']]);

    expect(ComponentTypeMap::forClassName('Filament\\Forms\\Components\\Textarea'))->toBe('text');
});

it('falls back to the built-in map when the host declares nothing', function () {
    config(['filament-mobile.types' => []]);

    expect(ComponentTypeMap::forClassName('Filament\\Forms\\Components\\Textarea'))->toBe('textarea');
});

it('ignores a host mapping to a type the contract does not define', function () {
    // A typo must not put an unrenderable type on the wire. The client would
    // show a debug placeholder in debug and nothing at all in release, which
    // is the silent-failure shape this project keeps closing.
    config(['filament-mobile.types' => ['App\\Vendor\\Thing' => 'not_a_real_type']]);

    expect(ComponentTypeMap::forClassName('App\\Vendor\\Thing'))->toBeNull();
});

it('still reports a genuinely unmapped class as null', function () {
    config(['filament-mobile.types' => []]);

    expect(ComponentTypeMap::forClassName('App\\Vendor\\Unknown'))->toBeNull();
});
