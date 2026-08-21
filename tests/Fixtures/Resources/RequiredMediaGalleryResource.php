<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Final review, finding 2: `GalleryResource` with `->required()` declared on
 * the single-file `cover` field — the media twin of
 * `RequiredSpatieTagsArticleResource`. `RuleExtractor` withholds a Laravel
 * rule for every relation-write name, so nothing else on mobile enforces
 * this; `MediaReconciler::isRequired()` is the only thing that does, and
 * only once the field IS present in the payload — `RecordForm::
 * saveRelations()`'s create-only absence check is what this fixture pins.
 */
class RequiredMediaGalleryResource extends GalleryResource
{
    protected static ?string $slug = 'required-media-galleries';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
            SpatieMediaLibraryFileUpload::make('cover')->collection('cover')->required(),
        ]);
    }
}
