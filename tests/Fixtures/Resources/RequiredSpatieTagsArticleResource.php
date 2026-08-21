<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * P15 Task 4: `ArticleResource` with `->required()` declared on the any-type
 * `tags` field. `RuleExtractor` withholds a Laravel rule for every
 * relation-write name (see `RecordForm::saveRelations()`'s docblock), so
 * nothing else on mobile enforces this — the write branch's own
 * `tagsRequired()` gate is the only thing that does, the same reasoning as
 * `MediaReconciler::isRequired()`.
 */
class RequiredSpatieTagsArticleResource extends ArticleResource
{
    protected static ?string $slug = 'required-spatie-tags-articles';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            SpatieTagsInput::make('tags')->required(),
        ]);
    }
}
