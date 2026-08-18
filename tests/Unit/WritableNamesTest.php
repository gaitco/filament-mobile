<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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

it('names a single-file upload field', function () {
    // P6a: RuleExtractor's file withholding narrowed to multiple-only, so a
    // single-file field's stored PATH (a string — never bytes, which travel
    // through Upload\UploadFieldResolver and the upload endpoint instead) is
    // writable through the ordinary payload exactly like any other leaf.
    expect(WritableNames::of([
        TextInput::make('name'),
        FileUpload::make('avatar'),
    ]))->toBe(['name', 'avatar']);
});

it('names a multiple-file field, since P12', function () {
    // A multiple field's value is a List<String> of stored paths the write
    // path saves wholesale, so its name is as writable as any other leaf's —
    // and the walker publishes it editable off the same admission.
    expect(WritableNames::of([
        TextInput::make('name'),
        FileUpload::make('gallery')->multiple(),
    ]))->toBe(['name', 'gallery']);
});

it('does not name a file field whose multiple() gate throws', function () {
    // A gate that cannot answer refuses. Admitting the rule on a throw
    // fails OPEN: the field enters this allow-set and PUT will write or
    // clear its column while the upload resolver refuses its every upload.
    // The closed answer here matches SchemaWalker's readOnly: true and the
    // resolver's refusal on the same throw.
    expect(WritableNames::of([
        TextInput::make('name'),
        FileUpload::make('boom')->multiple(fn () => throw new RuntimeException('broken gate')),
    ]))->toBe(['name']);
});

it('names a dotted path exactly as the state addresses it', function () {
    // State is addressed by path, not by flat key — the reset in Task 2 uses
    // these strings verbatim with Arr::get/Arr::set.
    expect(WritableNames::of([TextInput::make('caption.en')]))->toBe(['caption.en']);
});

it('names a repeater as ONE writable name, never its starred per-item paths', function () {
    // P6c Task 2: RuleExtractor::fromComponents() now ALSO publishes
    // `items.*.name` rules for a repeater's item template, but Arr::has()/
    // Arr::set() (SettledSchema::reset()) have no wildcard support — a
    // starred name here would silently drop every submitted row (Arr::has
    // never matches) or, if it ever did, write a literal `*` key. Neither is
    // acceptable, so this name space must stay separate from the rules one.
    $components = [
        Repeater::make('items')->schema([TextInput::make('name')->required()]),
    ];

    expect(WritableNames::of($components))->toBe(['items']);
});
