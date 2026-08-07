<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\NarrowedBannersRelationManager;

/**
 * Declares exactly one relation, and it is the refused one — so
 * `RelationDiscovery::for()` on this resource must be EMPTY, not merely
 * shorter. A refusal that still published the relation would be invisible in
 * a resource that had a publishable one alongside it.
 */
class NarrowedCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'narrowed-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            NarrowedBannersRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
