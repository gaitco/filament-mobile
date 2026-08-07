<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\ExplodingBannersRelationManager;

/**
 * CompanyResource with a gate that throws. The endpoint must read that as a
 * refusal — never as a 500, and never as "no opinion".
 *
 * Not in the shared fixture list in `TestCase`, for the same reason
 * CompanyResource is not.
 */
class ExplodingCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'exploding-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            ExplodingBannersRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
