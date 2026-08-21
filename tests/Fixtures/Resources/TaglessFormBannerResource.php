<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * Final review, finding 3: a `SpatieTagsInput::make('tags')` on `Banner`,
 * which has no `HasTags`/`syncTagsWithType()` but DOES declare a real
 * `tags()` BelongsToMany relation of its own (the plain fixture `Tag`, via
 * `banner_tag`) — unlike `TaglessCompanyResource`'s `Company`, which has no
 * `tags` relation at all. This is the shape that actually demonstrates the
 * bug `formProjection()`'s missing `$model` argument caused: the walker's
 * `tagsFailClosed()` needs `$model` to know Banner lacks HasTags, and
 * without it the field name survives into the form's `paths`, which
 * `RecordSerializer`'s generic form-field pass then reads straight off the
 * record — resolving Banner's real `tags()` relation and publishing whole
 * `Tag` models (with pivot rows) under the `tags` key.
 */
class TaglessFormBannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'tagless-form-banners';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SpatieTagsInput::make('tags'),
        ]);
    }
}
