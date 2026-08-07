<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\BannersRelationManager;

/**
 * `getRelations()` may legally return a `RelationGroup` or a
 * `RelationManagerConfiguration` as well as a bare class string. Neither is
 * handled this slice, and an unhandled entry is refused by name rather than
 * unwrapped on a guess — a group's members carry group-level configuration
 * this package would silently drop.
 */
class GroupedCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'grouped-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Marketing', [
                BannersRelationManager::class,
            ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
