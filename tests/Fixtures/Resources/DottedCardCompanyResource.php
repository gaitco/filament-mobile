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
 * A relation card with a DOTTED field, which is the only shape that can tell
 * an eager-loaded relation query apart from an N+1 one: `company.name` is
 * read per row by `RecordSerializer`, so without `->with()` each row costs an
 * extra query and the count grows with the page size.
 *
 * `index()` has carried that defence since P1 (`->with($card->relationPaths())`);
 * `RelationController` did not, and nothing measured the difference.
 */
class DottedCardCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'dotted-card-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->relationCard(
                'banners',
                fn (MobileCard $card) => $card->title('name')->subtitle('company.name'),
            );
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
