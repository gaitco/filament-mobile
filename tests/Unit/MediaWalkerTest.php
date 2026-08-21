<?php

declare(strict_types=1);

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\MobileCore\WalkWarnings;

/**
 * P14 Task 2: the two fail-closed publishes the design spec rules for a
 * Spatie media upload — a throwing `collection()` gate, and a component on a
 * model that never registered `HasMedia`. Both read as `readOnly: true`, the
 * same shape a throwing multiplicity/constraint gate already gets.
 */
it('publishes readOnly when the collection() closure throws', function () {
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        SpatieMediaLibraryFileUpload::make('photos')->collection(fn () => throw new RuntimeException('boom')),
    ], 'GalleryResource', 'galleries', Gallery::class);

    expect($nodes[0]['type'])->toBe('file')
        ->and($nodes[0]['config']['readOnly'])->toBeTrue();
});

it('publishes readOnly for a media upload on a model without HasMedia', function () {
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        SpatieMediaLibraryFileUpload::make('logo')->collection('logos'),
    ], 'CompanyResource', 'companies', Company::class);

    expect($nodes[0]['type'])->toBe('file')
        ->and($nodes[0]['config']['readOnly'])->toBeTrue();
});

it('publishes a media upload editable when the model has HasMedia and the collection resolves', function () {
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        SpatieMediaLibraryFileUpload::make('photos')->collection('photos'),
    ], 'GalleryResource', 'galleries', Gallery::class);

    expect($nodes[0]['config']['readOnly'])->toBeFalse();
});
