<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * A table with no columns, so `RelationCard::fromColumns()` derives nothing
 * and the host declares nothing either. `/schema` skips such a relation; the
 * endpoint for one was therefore never published, and must 404.
 */
class CardlessBannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public function table(Table $table): Table
    {
        return $table->columns([]);
    }
}
