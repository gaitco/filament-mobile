<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\SpatieTagsInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * P15 Task 4: doctor's diagnostic (3) — a card slot (`tags`) bound to a
 * `SpatieTagsInput` path on `Company`, which never registered `HasTags`.
 * `RecordSerializer`'s tags pass is gated on `method_exists($record,
 * 'syncTagsWithType')`, so this slot never gets a value written — it
 * publishes `[]` on every list/relation row, forever. Like
 * `TaglessCompanyResource`, this shape is ALSO already actionable via
 * `SchemaWalker::tagsFailClosed()` — see `DoctorCommand::tagProblems()`'s
 * docblock.
 */
class TaglessCardCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'tagless-card-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name')->meta('tags'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SpatieTagsInput::make('tags'),
        ]);
    }
}
