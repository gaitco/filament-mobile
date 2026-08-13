<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * L18 server half: a searchable select inside a repeater's item template.
 * The client renders it off the template and asks the options endpoint for
 * it by its bare child name — kept out of BannerResource (and the golden),
 * registered per test, the RuledBannerResource pattern.
 */
class RowSelectBannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'banners';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Repeater::make('line_items')->schema([
                TextInput::make('sku'),
                Select::make('row_company_id')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $query): array => Company::query()
                        ->where('name', 'like', "%{$query}%")
                        ->pluck('name', 'id')
                        ->all()),
            ]),
        ]);
    }
}
