<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An override that REPLACES Filament's default `canViewForRecord` — which is
 * `authorize('viewAny', $childModel)` — with an unconditional yes.
 *
 * It exists to isolate the third gate. With the default implementation the
 * child model's `viewAny` is already inside gate two, so a denied child would
 * never reach gate three and the gate could be deleted with every test still
 * green. Against this manager gate two passes by construction, and only the
 * independent child-model check can refuse.
 */
class AlwaysVisibleBannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('status'),
        ]);
    }
}
