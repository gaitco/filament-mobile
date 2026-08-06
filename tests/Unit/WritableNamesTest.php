<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Gait\FilamentMobile\Write\WritableNames;

it('names a plain writable field', function () {
    expect(WritableNames::of([TextInput::make('name')]))->toBe(['name']);
});

it('does not name a disabled field', function () {
    expect(WritableNames::of([
        TextInput::make('name'),
        TextInput::make('locked')->disabled(),
    ]))->toBe(['name']);
});

it('does not name anything inside a disabled container', function () {
    expect(WritableNames::of([
        Section::make()->schema([TextInput::make('inner')])->disabled(),
    ]))->toBe([]);
});

it('does not name a Hidden field', function () {
    // THE case that broke the refusal-based formulation. A Hidden is skipped
    // by ComponentTypeMap, so it is neither ruled nor refused — but its
    // submitted value still seeds the host and steers sibling gates. It must
    // not be trusted, which means it must not appear here.
    expect(WritableNames::of([
        TextInput::make('name'),
        Hidden::make('kind'),
    ]))->toBe(['name']);
});

it('does not name a file field', function () {
    // The walker publishes it read-only and the extractor withholds its rule,
    // so the write never accepts it — and a value it never accepts is a value
    // it must not evaluate gates against either.
    expect(WritableNames::of([
        TextInput::make('name'),
        FileUpload::make('avatar'),
    ]))->toBe(['name']);
});

it('names a dotted path exactly as the state addresses it', function () {
    // State is addressed by path, not by flat key — the reset in Task 2 uses
    // these strings verbatim with Arr::get/Arr::set.
    expect(WritableNames::of([TextInput::make('caption.en')]))->toBe(['caption.en']);
});
