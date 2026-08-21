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
 * P14 Task 5: doctor's diagnostic (3) — a card slot (`leadingImage`) bound to
 * a Spatie media path on a model without `HasMedia`. RecordSerializer's media
 * pass is gated on `method_exists($record, 'getMedia')`, so this slot never
 * gets its uuid value or `.__media` sibling written — it publishes null on
 * every list/relation row, forever. An infolist entry, not a form upload
 * (see MedialessResource's docblock): keeps this fixture clear of
 * SchemaWalker's own pre-existing, actionable "media upload on a model
 * without HasMedia" warning, so only the two NEW, informational findings
 * (diagnostics 1 and 3) are in play here.
 */
class MedialessCardResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'medialess-cards';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name')->leadingImage('photo'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            SpatieMediaLibraryImageEntry::make('photo')->collection('photo'),
        ]);
    }
}
