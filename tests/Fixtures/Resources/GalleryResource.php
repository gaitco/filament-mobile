<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;

/**
 * P14: the first fixture resource built on `spatie/laravel-medialibrary`'s
 * Filament components. Not in the shared fixture list in `TestCase` — same
 * reasoning as `CompanyResource`'s docblock: registering it there would add
 * `galleries` to the panel `ContractSnapshotTest`'s `ResourceRegistry()`
 * reads by default, changing `laravel-panel.json` even though that golden's
 * job is answering "server without media". The media slice's own tests
 * register it via `config()->set('filament-mobile.resources', …)` instead.
 */
class GalleryResource extends Resource
{
    protected static ?string $model = Gallery::class;

    protected static ?string $slug = 'galleries';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('name')
                ->leadingImage('cover'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
            SpatieMediaLibraryFileUpload::make('photos')->collection('photos')->multiple(),
            SpatieMediaLibraryFileUpload::make('cover')->collection('cover'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            SpatieMediaLibraryImageEntry::make('cover')->collection('cover'),
        ]);
    }
}
