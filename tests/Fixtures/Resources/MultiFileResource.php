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
 * One clean multi-file field and nothing else wrong — the fixture that
 * proves doctor's multi-file finding is informational, not actionable.
 *
 * BannerResource also has a multi-file field ('gallery') but ALSO an
 * unresolvable action name and a table action that carries a form, so a run
 * against it always exits 1 for those unrelated reasons and cannot isolate
 * the claim "a multi-file field alone must not fail CI". DriftResource is
 * deliberately rotten everywhere else for the same reason. Neither can stand
 * in for this one.
 *
 * NOT in the shared fixture resource list (`filament-mobile.resources` in
 * TestCase.php) — same reasoning as DriftResource: built to be broken (well,
 * built to be narrowly unsupported) must never skew the endpoint tests or
 * the contract snapshot.
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
