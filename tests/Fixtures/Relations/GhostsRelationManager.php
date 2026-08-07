<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * `$relationship = 'ghosts'` names no relation on `Company` — the ordinary
 * shape of a typo or a relation renamed on the model but not on the manager.
 *
 * Everything else about it is well-formed: the relationship NAME reads fine
 * and the table declares columns, so a card derives cleanly. Only actually
 * resolving `Company::ghosts()` fails, which is why the refusal has to build
 * the relationship rather than trust the name.
 */
class GhostsRelationManager extends RelationManager
{
    protected static string $relationship = 'ghosts';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
        ]);
    }
}
