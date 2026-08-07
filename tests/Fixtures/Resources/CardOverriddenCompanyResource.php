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
 * Same relation as CompanyResource — BannersRelationManager, columns
 * `name`, `status` — but declares `relationCard('banners', …)` with a title
 * that differs from what `RelationCard::fromColumns()` would derive
 * (`name`, the first column). Proves the precedence rule: the host's
 * declared card wins over the derived one. A resource that never overrides
 * this would pass a test asserting the derived title just as easily,
 * whether the precedence were wired correctly or backwards.
 */
class CardOverriddenCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'card-overridden-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->relationCard('banners', fn (MobileCard $card) => $card->title('status'));
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
