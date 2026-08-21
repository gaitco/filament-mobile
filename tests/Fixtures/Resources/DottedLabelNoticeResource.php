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
 * `tags_entry` slice, doctor's "Labels" section: a dotted name with no
 * `->label()` call publishes Filament's own name-derived default (the LAST
 * segment only), which reads fine beside a section heading on a wide desktop
 * grid but loses the relation context a phone list has no room to supply
 * another way. `category.name` on the FORM defaults; `category.slug` on the
 * INFOLIST carries an explicit `->label('Category')` and must not be named.
 * `Notice` needs no real `category` relation — `DoctorCommand::labelProblems()`
 * never resolves the path, only reads the component's own name/label.
 */
class DottedLabelNoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static ?string $slug = 'dotted-label-notices';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            TextInput::make('category.name'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            TextEntry::make('category.slug')->label('Category'),
        ]);
    }
}
