<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * Deliberately rotten, so `doctor` has something to find: every failure mode
 * the command reports is present exactly once.
 *
 * It is NOT in the shared fixture resource list — the doctor test adds it — so
 * a resource built to be broken can never skew the endpoint tests.
 */
class DriftResource extends Resource
{
    protected static ?string $model = Banner::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('name')
                // `ghost` is not a relation on Banner: an unresolvable path.
                ->subtitle('ghost.name'))
            ->searchable(['name'])
            // `nonexistent` is not a column on table(): drift.
            ->sorts(['nonexistent' => 'Nope', 'name' => 'Name']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
            // Unmapped by ComponentTypeMap: an unsupported component.
            ColorPicker::make('color'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            // Declared on the table but absent from mobile(): ignored column.
            TextColumn::make('status'),
        ]);
    }
}
