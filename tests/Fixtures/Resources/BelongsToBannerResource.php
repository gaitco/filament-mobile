<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Relations\CompanyRelationManager;

/**
 * A resource whose only relation is a BelongsTo — the relation type a create
 * cannot go through. Kept OUT of BannerResource (and so out of the
 * laravel-panel.json golden) and registered per test via config()->set, the
 * RuledBannerResource pattern.
 *
 * The point is the contrast with `TagsRelationManager` on BannerResource
 * itself: same suite, same `Company` served by exactly one resource, and the
 * write capability is withheld here purely because of the relationship's type.
 */
class BelongsToBannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'banners';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            CompanyRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
        ]);
    }
}
