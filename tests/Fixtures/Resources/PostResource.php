<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('title')
                ->subtitle('body'))
            ->searchable(['title'])
            ->sorts(['created_at' => 'Newest', 'title' => 'Title'])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Shows only `title`, while mobile() sorts by `created_at` — the most
     * ordinary Filament configuration there is, and the shape doctor must not
     * mistake for drift: a sort key is spent on the database, not the table.
     */
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
        ]);
    }

    /**
     * Deliberately covers every layout type and every field type in
     * ComponentTypeMap that no other fixture uses — grid, tabs, fieldset,
     * checkbox, date, datetime — so the contract snapshot, and therefore the
     * Filament 4/5 CI matrix, actually exercises their accessors. A type
     * absent from the fixtures is a type the matrix proves nothing about.
     *
     * The Tabs block uses the idiomatic `->tabs([Tab::make(...)])` rather than
     * `->schema()`, because that is the shape a real panel writes and the one
     * whose children must survive: a Tab maps to `section`, and the snapshot is
     * what proves the flattening keeps both the label and the contents.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(120),
            Textarea::make('body'),
            Toggle::make('published'),
            Checkbox::make('featured'),
            Grid::make(2)->schema([
                DatePicker::make('published_on'),
                DateTimePicker::make('published_at'),
            ]),
            // The four refined types — `refineType()` derives them from a
            // mapped component's accessors rather than from its class, so
            // nothing else in the fixtures reaches them, and CheckboxList is
            // the one mapped class no other resource uses. Optional and
            // column-less on purpose: they exist to be *walked*, not written.
            CheckboxList::make('tags')->options(['a' => 'A', 'b' => 'B']),
            TextInput::make('contact_email')->email(),
            TextInput::make('secret')->password(),
            TextInput::make('views')->numeric(),
            Tabs::make('More')->tabs([
                Tab::make('Details')->schema([
                    Fieldset::make('Audit')->schema([
                        TextInput::make('author'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            TextEntry::make('body'),
            // Conditional on purpose: an infolist entry may be reactive too,
            // and built without a schema host this closure fatals — which
            // show() would swallow as a silent fallback to the card's fields.
            IconEntry::make('published')
                ->boolean()
                ->visible(fn (Get $get) => $get('hide_published') !== true),
            ImageEntry::make('cover_url'),
        ]);
    }
}
