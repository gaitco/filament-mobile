<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\MobileCore\MobileCard;
use Gait\MobileCore\MobileResource;

/**
 * P18 Task 1: `ReorderDeclaration::for()`'s `direction: 'desc'` case, same
 * `Slide` model as `SlideResource` — only the table's declaration differs.
 */
class SlideDescResource extends Resource
{
    protected static ?string $model = Slide::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('position', direction: 'desc');
    }
}
