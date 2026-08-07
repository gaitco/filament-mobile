<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The ordinary case: two text columns, no query narrowing, no gate override.
 * Everything else in this directory is this class plus one deviation.
 */
class BannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('status'),
        ]);
    }
}
