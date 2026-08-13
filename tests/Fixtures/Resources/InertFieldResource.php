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
 * A `->dehydrated(false)` field that confirms nothing — the control for the
 * confirmation-sibling exception. This one must stay published disabled and
 * unwritable: it is the silent-drop case FieldPersistence::neverPersists()
 * exists to catch, and widening that exception past names a `confirmed` rule
 * actually reads would readmit it.
 */
class InertFieldResource extends Resource
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
            TextInput::make('scratch_note')->dehydrated(false),
        ]);
    }
}
