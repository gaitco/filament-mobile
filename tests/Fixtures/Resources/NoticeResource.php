<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Notice;

/**
 * P17: the first fixture resource built on the REAL
 * `spatie/laravel-translatable` `HasTranslations` trait — see `Notice`'s
 * docblock. Not in the shared fixture list in `TestCase` — same reasoning as
 * `PageResource`'s: registering it there would add `notices` to
 * `ContractSnapshotTest`'s `ResourceRegistry()` output. Registered per-test
 * via `config()->set('filament-mobile.resources', …)` instead.
 *
 * `caption.ar` / `caption.en` are the manual per-locale convention this
 * package has always supported (see the "Translatable" README section);
 * `title` is a plain, non-translatable column alongside them.
 */
class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static ?string $slug = 'notices';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            TextInput::make('caption.ar'),
            TextInput::make('caption.en'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
        ]);
    }
}
