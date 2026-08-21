<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * P15 Task 4: doctor's diagnostic (1) — a `SpatieTagsInput` on `Company`,
 * which never registered `HasTags`. Unlike medialibrary, this component has
 * no infolist-entry twin, so this exact shape is ALSO
 * `SchemaWalker::tagsFailClosed()`'s (Task 2) fail-closed drop, which the
 * pre-existing "Unsupported components" section already reports as
 * actionable — see `DoctorCommand::tagProblems()`'s docblock. This fixture
 * exercises the message text this section adds alongside that, not a
 * standalone informational-only case.
 *
 * Also reused by `SpatieTagsWriteTest` for the crafted-request pin (carried
 * requirement from Task 2's review): a request naming `tags` on this exact
 * traitless-model shape must not reach `$component->saveRelationships()`,
 * whatever the walker itself dropped. `name` is a real, writable column so
 * that write round-trips normally with nothing else to refuse it on.
 */
class TaglessCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'tagless-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
            SpatieTagsInput::make('tags'),
        ]);
    }
}
