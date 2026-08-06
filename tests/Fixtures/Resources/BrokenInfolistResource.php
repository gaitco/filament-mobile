<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * A resource whose infolist cannot be built at all outside a record context.
 *
 * `visible()` is typed against the model, and `getComponents()` evaluates it to
 * decide what to return — with no record, that is a TypeError thrown during
 * schema *construction*, before the walker sees a single component. It is
 * therefore outside SafeEvaluator's reach, which guards the walk only.
 *
 * This is not a contrived shape: the pilot found it on 2 of that panel's 35
 * resources, and unguarded it turned the entire /schema document into a 500 —
 * one resource taking down every other resource's schema.
 *
 * Like DriftResource, this is NOT in the shared fixture list; the tests that
 * need it add it, so a resource built to be broken cannot skew anything else.
 */
class BrokenInfolistResource extends Resource
{
    protected static ?string $model = Banner::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->sorts(['name' => 'Name']);
    }

    public static function form(Schema $schema): Schema
    {
        // The form is fine — only the infolist is broken, so the tests can
        // prove the failure is isolated to the one schema that threw.
        return $schema->components([
            TextEntry::make('name'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // The closure is the *schema's own* component list, not a `visible()`
        // on one component, and that distinction is now load-bearing: /schema
        // reads `getComponents(withHidden: true)`, which skips the hidden
        // filter, so a record-typed `visible()` no longer throws during
        // construction — it degrades to a per-component warning in the walk,
        // which is the point of that change. `evaluate($this->components)` is
        // the construction hazard that survives it.
        return $schema->components(fn (Banner $record): array => [
            Section::make('Boom')->schema([
                TextEntry::make('name'),
            ]),
        ]);
    }
}
