<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Gait\FilamentMobile\Introspection\FieldPersistence;
use Gait\FilamentMobile\Introspection\MediaFields;

it('recognises the spatie upload and entry by class name', function () {
    expect(MediaFields::isMediaUpload(SpatieMediaLibraryFileUpload::make('photos')))->toBeTrue()
        ->and(MediaFields::isMediaUpload(FileUpload::make('photos')))->toBeFalse()
        ->and(MediaFields::isMediaEntry(SpatieMediaLibraryImageEntry::make('cover')))->toBeTrue();
});

it('reads the declared collection, defaulting and failing closed', function () {
    expect(MediaFields::collectionOf(SpatieMediaLibraryFileUpload::make('photos')->collection('photos')))
        ->toBe('photos')
        ->and(MediaFields::collectionOf(SpatieMediaLibraryFileUpload::make('x')))->toBe('default')
        ->and(MediaFields::collectionOf(
            SpatieMediaLibraryFileUpload::make('x')->collection(fn () => throw new RuntimeException('boom')),
        ))->toBeNull();
});

it('classifies a media upload as relationship-saved so it publishes editable', function () {
    $field = SpatieMediaLibraryFileUpload::make('photos')->collection('photos');

    expect(FieldPersistence::savesViaRelationship($field))->toBeTrue()
        ->and(FieldPersistence::neverPersists($field))->toBeFalse();
});

it('maps leaf names to collection and multiplicity for uploads and entries', function () {
    $paths = MediaFields::pathsIn([
        SpatieMediaLibraryFileUpload::make('photos')->collection('photos')->multiple(),
        SpatieMediaLibraryFileUpload::make('cover')->collection('cover'),
        SpatieMediaLibraryImageEntry::make('avatar')->collection('avatars'),
        FileUpload::make('plain'),
    ]);

    expect($paths)->toBe([
        'photos' => ['collection' => 'photos', 'multiple' => true],
        'cover' => ['collection' => 'cover', 'multiple' => false],
        'avatar' => ['collection' => 'avatars', 'multiple' => false],
    ]);
});

it('drops a media component whose collection closure throws from pathsIn, rather than guessing', function () {
    $paths = MediaFields::pathsIn([
        SpatieMediaLibraryFileUpload::make('broken')->collection(fn () => throw new RuntimeException('boom')),
    ]);

    expect($paths)->toBe([]);
});
