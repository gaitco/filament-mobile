<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The refusal's subject. `modifyQueryUsing()` registers a query scope this
 * package can detect but cannot reproduce — measured outside Livewire,
 * `Table::getQuery()` yields `select * where "status" = ?` against a NULL
 * model, so the author's narrowing survives while the relation binding does
 * not. Publishing this relation would list rows the web panel hides.
 */
class NarrowedBannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'active'))
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('status'),
            ]);
    }
}
