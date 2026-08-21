<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * A dotted infolist entry over Banner's BelongsTo — the fixture behind the
 * entry `target` node. Whether the target publishes depends on whether
 * CompanyResource is registered alongside, so the tests register both
 * combinations. Not in the shared fixture list, same reasoning as
 * `ArticleResource`'s docblock.
 */
class LinkedEntryBannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'linked-banners';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card): MobileCard => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('company.name'),
        ]);
    }
}
