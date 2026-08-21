<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Page;

/**
 * P16: the first fixture resource built on `spatie/laravel-sluggable`'s
 * `HasSlug`. Not in the shared fixture list in `TestCase` — same reasoning
 * as `GalleryResource`'s and `ArticleResource`'s docblocks: registering it
 * there would add `pages` to `ContractSnapshotTest`'s `ResourceRegistry()`
 * output. Registered per-test via `config()->set('filament-mobile.resources', …)`
 * instead. `slug` is a plain `TextInput` — no Filament plugin exists for
 * sluggable, and none is needed: this package requires no special handling
 * of the field at all (see the "Sluggable" README section).
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $slug = 'pages';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            TextInput::make('slug'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
        ]);
    }
}
