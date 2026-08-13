<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The BelongsTo case — `Banner::company()` — and the reason it exists is the
 * WRITE side, not the read one.
 *
 * Reading a BelongsTo relation is fine and stays published. But creating
 * through it is not: `BelongsTo` has no `create()`, so `Relation::__call`
 * forwards to the query builder and a brand new Company is inserted with
 * nothing pointing at it — the endpoint would answer `201` for a row that is
 * not in the relation, and the client's refresh would not find it. Its verb is
 * `associate()`, which writes the PARENT.
 *
 * `Company` IS served by exactly one mobile resource (`CompanyResource`), so
 * this fixture isolates the relation TYPE as the reason writes are withheld —
 * the owner-count rule would happily have published them.
 */
class CompanyRelationManager extends RelationManager
{
    protected static string $relationship = 'company';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
        ]);
    }
}
