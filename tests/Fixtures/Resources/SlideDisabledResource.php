<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\MobileCore\MobileCard;
use Gait\MobileCore\MobileResource;

/**
 * P18 final-review Critical fix: `->reorderable()`'s own `condition` — the
 * middle term of Filament's `isReorderable()` (`filled(reorderColumn) &&
 * evaluate($isReorderable) && isReorderAuthorized()`,
 * `CanReorderRecords.php:104`) — independent of `authorizeReorder()`. This
 * resource authorizes everyone but declares `condition: false`, so it must
 * read as "not reorderable" on the schema key and `?reorder=1` (both fold
 * `for()` + `authorizes()`, same as an authorizeReorder() denial). The write
 * endpoint tells the two apart by column, not by condition, so this reads as
 * 403 there — the column IS declared, just not enabled — never the 404 a
 * resource with no reorder column gets. Same `Slide` model as `SlideResource`
 * — only the table's declaration differs.
 */
class SlideDisabledResource extends Resource
{
    protected static ?string $model = Slide::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('position', condition: false);
    }
}
