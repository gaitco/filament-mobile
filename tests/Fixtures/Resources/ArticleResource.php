<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Article;

/**
 * P15: the first fixture resource built on `filament/spatie-laravel-tags-plugin`.
 * Not in the shared fixture list in `TestCase` — same reasoning as
 * `GalleryResource`'s docblock: registering it there would add `articles`
 * to `ContractSnapshotTest`'s `ResourceRegistry()` output. Registered
 * per-test via `config()->set('filament-mobile.resources', …)` instead.
 *
 * `tags` is the any-type field, `topics` the typed one — the exact names
 * later P15 tasks' walker/serializer/write tests instantiate against, so
 * they stay as declared here. At the end of Task 1 the walker still DROPS
 * both fields (unmapped until Task 2's `TagFields` classification lands).
 *
 * The infolist's `SpatieTagsEntry` pair mirrors the host panel exactly (see
 * this slice's spec: `ArticleResource::infolist()` declares
 * `SpatieTagsEntry::make('tags')` and `SpatieTagsEntry::make('topics')
 * ->type('topics')`, and the detail screen showed neither before
 * `ComponentTypeMap` learned the class). Same names as the form on purpose —
 * every subclass below (`RequiredSpatieTagsArticleResource`,
 * `SeparatedSpatieTagsArticleResource`, `CardedTagsArticleResource`) inherits
 * this infolist unchanged, and `TagFields::pathsIn()`'s union folds an entry
 * and an input sharing a name into one path, so no existing assertion in
 * `SpatieTagsSerializationTest`/`TagsWriteTest`/`SpatieTagsWriteTest` shifts.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $slug = 'articles';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            SpatieTagsInput::make('tags'),
            SpatieTagsInput::make('topics')->type('topics'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            SpatieTagsEntry::make('tags'),
            SpatieTagsEntry::make('topics')->type('topics'),
        ]);
    }
}
