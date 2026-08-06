<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Support\TagGroupEnum;
use UnitEnum;

/**
 * The one fixture whose navigation group is a backed enum rather than a
 * plain string — the shape `/schema` must resolve via `HasLabel::getLabel()`
 * instead of a naive `(string)` cast, which fatals on an enum object.
 *
 * Not in the shared fixture list — the group tests add it — so a resource
 * built only to exercise the enum branch cannot skew any other test's
 * resource count.
 */
class TagResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static string | UnitEnum | null $navigationGroup = TagGroupEnum::Catalog;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->sorts(['name' => 'Name']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
