<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\MobileCore\MobileCard;
use Gait\MobileCore\MobileResource;

/**
 * P18 Task 4: same `Slide` model and rows as `SlideResource`, but declares a
 * reorder column that does not exist on the `slides` table
 * (`->reorderable('missing_column')`). `ReorderDeclaration::for()` only
 * checks blankness/dottedness — never that the column is real — so this
 * resource reads as reorderable right up to the UPDATE, which throws on an
 * unknown column. That is the fixture ReorderWriteTest's transaction-rollback
 * case needs: a write that fails INSIDE `DB::transaction()`, proving nothing
 * partial survives.
 */
class SlideBrokenResource extends Resource
{
    protected static ?string $model = Slide::class;

    protected static ?string $slug = 'slide-brokens';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('missing_column');
    }
}
