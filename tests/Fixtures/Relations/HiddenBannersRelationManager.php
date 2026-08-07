<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The second authorization gate, denying. Discovery still publishes this one
 * — `canViewForRecord()` is per-record and needs an owner, so it is the
 * request path's gate, not discovery's. Later tasks consume it.
 */
class HiddenBannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('status'),
        ]);
    }
}
