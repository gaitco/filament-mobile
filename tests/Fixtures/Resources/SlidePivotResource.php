<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\MobileCore\MobileCard;
use Gait\MobileCore\MobileResource;

/**
 * P18 Task 1: a BelongsToMany pivot reorder — Filament's OTHER reorder
 * branch, out of scope for P18. `ReorderDeclaration::for()` must read the
 * dotted column as "not reorderable" rather than publish a column mobile
 * can't write.
 */
class SlidePivotResource extends Resource
{
    protected static ?string $model = Slide::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('pivot.position');
    }
}
