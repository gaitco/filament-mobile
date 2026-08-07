<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The BelongsToMany case, on the golden-snapshot fixture itself
 * (`BannerResource` is already in `TestCase`'s shared list). Every other
 * relation fixture in this directory is a HasMany — Task 4's endpoint calls
 * `$record->{$key}()` and `->paginate()` on whatever `getRelationship()`
 * returns, and a pivot-backed relation is the shape that would catch a
 * BelongsToMany handled as if it were a HasMany.
 */
class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
        ]);
    }
}
