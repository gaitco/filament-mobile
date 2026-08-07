<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\BannersRelationManager;

/**
 * The two ways `relationCard()` is misdeclared, on one resource:
 *
 * - `banners` is configured with NO slot at all, so the host declared a card
 *   that renders nothing. Non-null, so a `$card === null` check waves it
 *   through and the relation ships as rows carrying only their record key.
 * - `bannerz` is a typo. Nothing on this resource is named that, so the
 *   declaration is inert and the derived card is used instead — silently,
 *   which is how a panel author spends an afternoon on a card that was never
 *   read.
 *
 * Both are the panel author's own mistake, so `doctor` is where they belong.
 */
class MisdeclaredCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'misdeclared-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->relationCard('banners', fn (MobileCard $card) => $card)
            ->relationCard('bannerz', fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            BannersRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
