<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use RuntimeException;

/**
 * Final review, finding 3: a `SpatieTagsInput` whose `->type()` closure
 * throws, on `Article` (which DOES have `HasTags`). `SchemaWalker::
 * tagsFailClosed()` drops this field for the SAME reason on every request —
 * `TagFields::typeOf()` is model-independent — so this fixture pins that the
 * `show()` endpoint's edit-form projection agrees with `/schema` on it, now
 * that `formProjection()` passes `$model` the same way `infolistPaths()`
 * already did.
 *
 * `infolist()` is overridden to drop `ArticleResource`'s inherited
 * `SpatieTagsEntry::make('tags')`: this fixture's whole point is that the
 * FORM's throwing gate drops `tags` from the projection, and since the
 * `tags_entry` fold (this task) folds a working infolist half in beside a
 * throwing form half, leaving the entry in place would publish real data for
 * `tags` from a DIFFERENT, non-throwing component sharing the name — proving
 * nothing about the throw this fixture exists to pin.
 */
class ThrowingTypeArticleResource extends ArticleResource
{
    protected static ?string $slug = 'throwing-type-articles';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            SpatieTagsInput::make('tags')->type(fn () => throw new RuntimeException('boom')),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
        ]);
    }
}
