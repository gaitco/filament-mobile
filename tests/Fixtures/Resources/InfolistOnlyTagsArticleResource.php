<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * `tags_entry` slice: a `SpatieTagsEntry` for a path the FORM never
 * declares at all — `curated`, absent from both `form()` here and from
 * `ArticleResource::form()` it overrides. This is the one shape that
 * actually proves `MobilePanelController::tagPathsFor()`/`cardTagPaths()`
 * fold `infolist` in rather than merely tolerate an entry that shadows a
 * form field of the same name (which `ArticleResource`'s own `tags`/
 * `topics` pair already does, silently, since neither side's value can
 * differ): before the fold, `curated` was schema-only (walked, published on
 * `/schema`) but never appeared in a record payload, because `formProjection()`
 * built `tagPaths` from the form's components alone.
 */
class InfolistOnlyTagsArticleResource extends ArticleResource
{
    protected static ?string $slug = 'infolist-only-tags-articles';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            SpatieTagsEntry::make('curated'),
        ]);
    }
}
