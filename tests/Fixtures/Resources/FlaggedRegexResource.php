<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * A `->regex()` whose pattern carries a FLAG the client's regex engine has no
 * inline form for. The hint is withheld rather than published stripped, which
 * would make the client stricter than the server — see SchemaWalker's
 * undelimitedPattern(). Registered per test via config()->set, the
 * RuledBannerResource pattern.
 */
class FlaggedRegexResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'banners';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('handle')->regex('/^[a-z0-9_]+$/i'),
        ]);
    }
}
