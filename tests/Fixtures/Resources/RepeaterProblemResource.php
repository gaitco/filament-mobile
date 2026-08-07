<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * The four repeater shapes this slice legitimately cannot support, and
 * nothing else wrong — the fixture that proves doctor's repeater findings
 * are informational, not actionable.
 *
 * BannerResource also carries a relationship repeater (`tag_rows`), but ALSO
 * an unresolvable action name and a table action that carries a form, so a
 * run against it always exits 1 for unrelated reasons and cannot isolate the
 * claim "these three shapes alone must not fail CI". Same reasoning as
 * MultiFileResource.
 *
 * None of these fields need a real column: doctor only builds the `form`
 * schema to walk it, it never writes through this resource.
 *
 * NOT in the shared fixture resource list (`filament-mobile.resources` in
 * TestCase.php), same as MultiFileResource and DriftResource — built to
 * exercise one narrow claim, never the endpoint tests or the contract
 * snapshot.
 */
class RepeaterProblemResource extends Resource
{
    protected static ?string $model = Banner::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Writes child rows through Filament's own saveRelationships(),
            // which this package's write path never calls.
            Repeater::make('rel_rows')
                ->relationship('tags')
                ->schema([TextInput::make('name')]),
            // The item template is static — a live() field inside a row will
            // not re-settle that row.
            Repeater::make('live_rows')
                ->schema([TextInput::make('note')->live()]),
            // Two levels of row coordinate is a different problem — the
            // walker publishes the inner repeater but the client renders it
            // read-only.
            Repeater::make('outer_rows')
                ->schema([
                    Repeater::make('inner_rows')->schema([TextInput::make('x')]),
                ]),
            // A child that would not round-trip. `Hidden` is dropped by
            // ComponentTypeMap::SKIPPED, so no rule ever names it, and the
            // row array is written whole — the key would be deleted from
            // every row on save. Doctor is the only place a panel author can
            // learn WHICH child cost them the control.
            Repeater::make('guarded_rows')
                ->schema([
                    TextInput::make('sku'),
                    Hidden::make('id'),
                ]),
        ]);
    }
}
