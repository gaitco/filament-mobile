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
 * A6: the three rules the wire never carried — `url`, `regex`, `confirmed`.
 * Kept OUT of BannerResource (and so out of the laravel-panel.json golden):
 * these fields exist to prove emission and enforcement, registered per test
 * via config()->set, the BrokenGroupResource pattern.
 */
class RuledBannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    // The mobile key is the Filament slug (ResourceRegistry::keyFor) —
    // pinned so tests address `/api/mobile-panel/banners` regardless of
    // this class's name.
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
            TextInput::make('website')->url(),
            TextInput::make('handle')->regex('/^[a-z0-9_]+$/'),
            TextInput::make('access_token')->confirmed(),
            // Filament's own confirmation idiom: the sibling field is never
            // persisted, only read by the `confirmed` rule at validation time.
            TextInput::make('access_token_confirmation')->dehydrated(false),
        ]);
    }
}
