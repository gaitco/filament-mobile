<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A gate that cannot answer. The package's standing rule is that such a gate
 * refuses rather than propagates — the request path must read this as "no",
 * never as "no opinion" and never as a 500.
 */
class ExplodingBannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        throw new RuntimeException('deliberately broken relation view gate');
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('status'),
        ]);
    }
}
