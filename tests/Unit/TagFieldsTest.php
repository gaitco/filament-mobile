<?php

declare(strict_types=1);

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Gait\FilamentMobile\Introspection\FieldPersistence;
use Gait\FilamentMobile\Introspection\TagFields;

it('recognises the spatie tags input by class name', function () {
    expect(TagFields::isSpatieTags(SpatieTagsInput::make('x')))->toBeTrue()
        ->and(TagFields::isSpatieTags(TagsInput::make('x')))->toBeFalse();
});

it('recognises the spatie tags entry by class name too', function () {
    // The infolist twin: P15's stated premise ("no infolist tags entry
    // exists") is what this task refutes.
    expect(TagFields::isSpatieTags(SpatieTagsEntry::make('x')))->toBeTrue()
        ->and(TagFields::isSpatieTags(TextEntry::make('x')))->toBeFalse();
});

it('reads the entry declared type the same way as the field, any-type by default', function () {
    // SpatieTagsEntry::setUp() unconditionally calls $this->type(AllTagTypes::make())
    // (measured in vendor), the exact shape the field defaults to.
    expect(TagFields::typeOf(SpatieTagsEntry::make('x')))->toBe(['any' => true, 'type' => null])
        ->and(TagFields::typeOf(SpatieTagsEntry::make('x')->type('topics')))
        ->toBe(['any' => false, 'type' => 'topics']);
});

it('reads the declared type, defaulting to any-type and failing closed', function () {
    expect(TagFields::typeOf(SpatieTagsInput::make('x')))->toBe(['any' => true, 'type' => null])
        ->and(TagFields::typeOf(SpatieTagsInput::make('x')->type('topics')))
        ->toBe(['any' => false, 'type' => 'topics'])
        ->and(TagFields::typeOf(
            SpatieTagsInput::make('x')->type(fn () => throw new RuntimeException('boom')),
        ))->toBeNull();
});

it('classifies a spatie tags field as relationship-saved so it publishes editable', function () {
    $field = SpatieTagsInput::make('tags');

    expect(FieldPersistence::savesViaRelationship($field))->toBeTrue()
        ->and(FieldPersistence::neverPersists($field))->toBeFalse();
});

it('maps leaf names to any/type metadata for spatie tags fields', function () {
    $paths = TagFields::pathsIn([
        SpatieTagsInput::make('tags'),
        SpatieTagsInput::make('topics')->type('topics'),
        TagsInput::make('plain'),
    ]);

    expect($paths)->toBe([
        'tags' => ['any' => true, 'type' => null],
        'topics' => ['any' => false, 'type' => 'topics'],
    ]);
});

it('collects an entry path alongside a field path, any and typed', function () {
    $paths = TagFields::pathsIn([
        SpatieTagsEntry::make('tags'),
        SpatieTagsEntry::make('topics')->type('topics'),
        TextEntry::make('title'),
    ]);

    expect($paths)->toBe([
        'tags' => ['any' => true, 'type' => null],
        'topics' => ['any' => false, 'type' => 'topics'],
    ]);
});

it('omits a spatie tags field whose type closure throws from pathsIn, rather than guessing', function () {
    $paths = TagFields::pathsIn([
        SpatieTagsInput::make('broken')->type(fn () => throw new RuntimeException('boom')),
    ]);

    expect($paths)->toBe([]);
});
