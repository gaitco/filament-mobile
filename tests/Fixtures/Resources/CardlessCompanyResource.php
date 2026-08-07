<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\CardlessBannersRelationManager;

/**
 * A relation nothing can render: no derived card, no declared one. See
 * CardlessBannersRelationManager.
 *
 * Not in the shared fixture list in `TestCase`, for the same reason
 * CompanyResource is not.
 */
class CardlessCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'cardless-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            CardlessBannersRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
