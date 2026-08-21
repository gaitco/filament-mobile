<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Article;

/**
 * P15 Task 4: doctor's diagnostic (2) — a `SpatieTagsInput` whose
 * `getName()` collides with a real column on the model's table. `Article`
 * (unlike `Company`) has `HasTags`, so diagnostic (1) does not also fire
 * here; `articles.title` is a real string column, and `title` is also this
 * component's identifier, so `RecordSerializer`'s tags pass overwrites
 * whatever the column read wrote — almost certainly a mistake, not a
 * deliberate shadow. The only fixture of the three doctor tests below that
 * is genuinely clean (exit 0): `Article` has `HasTags`, so no
 * `SchemaWalker::tagsFailClosed()` warning fires anywhere in this form.
 */
class ColumnCollisionArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $slug = 'column-collision-articles';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SpatieTagsInput::make('title'),
        ]);
    }
}
