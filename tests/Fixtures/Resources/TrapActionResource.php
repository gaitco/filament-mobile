<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use RuntimeException;

/**
 * A single action whose visibility closure throws, so ActionResolverTest can
 * pin the fail-closed rule against a table this narrow: `available()` must
 * degrade the throw to omission, not a fatal.
 *
 * NOT in the shared fixture resource list (`filament-mobile.resources` in
 * TestCase.php) — same reasoning as DriftResource: a resource built to be
 * broken must never skew the endpoint tests or the contract snapshot. It
 * exists for this unit test only, constructed directly.
 */
class TrapActionResource extends Resource
{
    protected static ?string $model = Banner::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->actions(['trapdoor']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->recordActions([
            Action::make('trapdoor')
                ->visible(fn () => throw new RuntimeException('nope'))
                ->action(fn () => null),
        ]);
    }
}
