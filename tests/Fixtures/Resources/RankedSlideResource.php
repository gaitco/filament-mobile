<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Gait\FilamentMobile\Tests\Fixtures\Models\RankedSlide;
use Gait\MobileCore\MobileCard;
use Gait\MobileCore\MobileResource;

/**
 * P18 Task 5: a resource using RankedSlide model (with Spatie sortable
 * configured to `rank`) but declaring reorderable('position'). This
 * mismatch between the table reorder column and the model's sortable
 * order_column_name is the diagnostic (b) case.
 */
class RankedSlideResource extends Resource
{
    protected static ?string $model = RankedSlide::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('position');
    }
}
