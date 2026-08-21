<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;

/**
 * P14 Task 5: doctor's diagnostic (2) — a Spatie media component whose
 * `getName()` collides with a real column on the model's table. Gallery
 * (unlike Company) has `HasMedia`, so diagnostic (1) does not also fire here;
 * `galleries.name` is a real string column, and `name` is also this
 * component's identifier, so RecordSerializer's media pass
 * (`$payload[$path] = …`) overwrites whatever the column read wrote —
 * almost certainly a mistake, not a deliberate shadow.
 */
class ColumnCollisionResource extends Resource
{
    protected static ?string $model = Gallery::class;

    protected static ?string $slug = 'column-collision-galleries';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SpatieMediaLibraryFileUpload::make('name')->collection('photos'),
        ]);
    }
}
