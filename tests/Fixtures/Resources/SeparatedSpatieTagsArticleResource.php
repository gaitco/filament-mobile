<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Schemas\Schema;

/**
 * P15 Task 2: `ArticleResource` with a `->separator(',')` declared on the
 * any-type Spatie field — a shape that must never reach
 * `TagSeparators::forResource()`'s map. A Spatie tags field has no column of
 * its own (Filament's own `saveRelationshipsUsing` writes it, never a
 * `dehydrateStateUsing` implode), so mirroring its separator the way a plain
 * `TagsInput` earns would implode the submitted list into a column that does
 * not exist.
 *
 * A separate resource rather than a `->separator()` on `ArticleResource`
 * itself, same reasoning as `CardedTagsBannerResource`: this is a per-test
 * shape, never registered in the shared fixture list.
 */
class SeparatedSpatieTagsArticleResource extends ArticleResource
{
    protected static ?string $slug = 'separated-spatie-tags-articles';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SpatieTagsInput::make('tags')->separator(','),
        ]);
    }
}
