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
 * The first fixture resource that declares `getRelations()` at all — a
 * company whose whole point is the banners underneath it. The ordinary,
 * publishable case.
 *
 * Not in the shared fixture list in `TestCase`: the relation tests that need
 * it register it themselves, so a resource added for one slice cannot shift
 * every other test's resource count.
 */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
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
