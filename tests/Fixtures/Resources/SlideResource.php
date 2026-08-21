<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\MobileCore\MobileCard;
use Gait\MobileCore\MobileResource;

/**
 * P18 Task 1: `ReorderDeclaration::for()`'s plain case — an ascending
 * `->reorderable('position')`. `authorizeReorder()` reads `request()->user()`,
 * exactly as a real panel's closure would, which is what
 * `ReorderDeclaration::authorizes()` exists to evaluate headlessly.
 */
class SlideResource extends Resource
{
    protected static ?string $model = Slide::class;

    public static function mobile(): MobileResource
    {
        // P18 Task 3: searchable on `title` — ReorderListTest's search-narrows
        // case needs a real searchable() declaration to exercise, since
        // `?reorder=1` keeps search applied (Filament's own behaviour).
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'))
            ->searchable(['title']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->authorizeReorder(fn () => request()->user()?->email === 'admin@example.test');
    }
}
