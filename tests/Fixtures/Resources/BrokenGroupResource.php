<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use RuntimeException;
use UnitEnum;

/**
 * `getNavigationGroup()` throws — the same shape every other accessor this
 * package reads defensively degrades from. SafeEvaluator must fall the
 * resource's group back to absent rather than 500ing the whole /schema
 * document over one resource's navigation group.
 *
 * Not in the shared fixture list — the group tests add it — so a resource
 * built to be broken cannot skew any other test's resource count.
 */
class BrokenGroupResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'broken-group';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->sorts(['name' => 'Name']);
    }

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        throw new RuntimeException('deliberately broken navigation group accessor');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
