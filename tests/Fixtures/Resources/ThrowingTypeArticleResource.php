<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
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
}
