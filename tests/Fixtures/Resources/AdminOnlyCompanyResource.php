<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\AdminOnlyBannersRelationManager;

/**
 * CompanyResource whose relation gate reads the panel's user rather than
 * re-asking a model policy. See AdminOnlyBannersRelationManager.
 *
 * Not in the shared fixture list in `TestCase`, for the same reason
 * CompanyResource is not.
 */
class AdminOnlyCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'admin-only-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            AdminOnlyBannersRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
