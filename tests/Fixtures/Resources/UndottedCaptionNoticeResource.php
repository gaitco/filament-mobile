<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Notice;

/**
 * P17 Task 2: the doctor fixture for the OTHER translatable shape — an
 * UNDOTTED field bound directly to a translatable attribute, the official
 * `filament/spatie-laravel-translatable-plugin`'s convention (that plugin
 * wraps the whole form and swaps the current locale under one field, rather
 * than this package's own dotted-sibling convention `NoticeResource` fixtures).
 * `DoctorCommand`'s new diagnostic names this shape: mobile has no locale
 * switcher of its own, so an undotted `caption` here only ever edits whatever
 * locale the request resolves to.
 */
class UndottedCaptionNoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static ?string $slug = 'undotted-notices';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            TextInput::make('caption'),
        ]);
    }
}
