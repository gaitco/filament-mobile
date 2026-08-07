<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations;

use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The idiomatic host override: a gate that reads the panel's own user.
 *
 * It exists to pin WHICH identity gate 2 is asked about. Filament resolves
 * `Filament::auth()` from the PANEL's guard, which the route's `auth:{guard}`
 * middleware never rewrites — `Auth::shouldUse()` moves the DEFAULT guard, not
 * the panel's. Left ambient, this gate answers for whoever happens to hold a
 * panel session, which on a token-authenticated request is a stranger: it
 * refused users their own policy allowed, and served rows to a caller because
 * an unrelated admin's cookie rode along.
 */
class AdminOnlyBannersRelationManager extends RelationManager
{
    protected static string $relationship = 'banners';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return str_contains((string) (Filament::auth()->user()?->name ?? ''), 'admin');
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('status'),
        ]);
    }
}
