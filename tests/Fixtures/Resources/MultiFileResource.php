<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\FileUpload;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * One clean multi-file field and nothing else — the fixture that proves a
 * multi-file field is fully SUPPORTED since P12: doctor says nothing about
 * it and exits 0, the schema publishes it editable, and the write path
 * saves its list of paths.
 *
 * BannerResource also has multi-file fields ('gallery', 'attachments') but
 * ALSO an unresolvable action name and a table action that carries a form,
 * so a run against it always exits 1 for those unrelated reasons and cannot
 * isolate the claim "a multi-file field alone is a clean bill of health".
 * DriftResource is deliberately rotten everywhere else for the same reason.
 * Neither can stand in for this one.
 *
 * NOT in the shared fixture resource list (`filament-mobile.resources` in
 * TestCase.php) — same reasoning as DriftResource: a single-purpose fixture
 * must never skew the endpoint tests or the contract snapshot.
 */
class MultiFileResource extends Resource
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
            FileUpload::make('photos')->multiple(),
        ]);
    }
}
