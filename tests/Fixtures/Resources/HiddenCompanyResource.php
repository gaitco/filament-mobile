<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\HiddenBannersRelationManager;

/**
 * CompanyResource with the denying gate. The relation IS published — a
 * `canViewForRecord` answer needs an owner record, so it is the request
 * path's gate, never discovery's — which is exactly why the endpoint must
 * answer 403 rather than an empty list.
 *
 * Not in the shared fixture list in `TestCase`, for the same reason
 * CompanyResource is not: a resource added for one slice must not shift every
 * other test's resource count.
 */
class HiddenCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'hidden-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            HiddenBannersRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
