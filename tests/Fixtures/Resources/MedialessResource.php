<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * P14 Task 5: a Spatie media INFOLIST entry on a model that never registered
 * `HasMedia`. Deliberately an entry, not a form upload: SchemaWalker (Task 2)
 * already warns — actionably, via WalkWarnings — when a media UPLOAD sits on
 * a model without HasMedia (see SchemaWalker::config(), the 'file' type
 * branch); an entry has no such existing signal at all, so this is the
 * genuinely new finding doctor's Medialibrary section adds. Not bound to any
 * card slot, so it exercises only that one finding — see
 * MedialessCardResource for the card-bound variant.
 */
class MedialessResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'medialess';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            SpatieMediaLibraryImageEntry::make('photo')->collection('photo'),
        ]);
    }
}
