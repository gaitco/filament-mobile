<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Carbon\Carbon;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\SpatieTagsEntry;
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
                // Deliberately unbounded — the pair with `published_at` below
                // proves both cases in the golden snapshot itself: a date
                // node with real minDate/maxDate, and one with neither. See
                // DateConfigTest.php for the exhaustive (bounded/unbounded/
                // exploding) unit coverage; this fixture exists only so the
                // Dart-side contract test parses a REAL server-emitted bound,
                // not one hand-written into a Dart test fixture (fix round 1,
                // P8 Task 1 — the P6d `relations: []` shape).
                DatePicker::make('published_on'),
                DateTimePicker::make('published_at')
                    ->minDate('2026-01-01')
                    ->maxDate('2026-12-31'),
                // P8 Task 2, same bounded/unbounded pairing and for the same
                // reason: a `time` fixture with no bounds proves nothing about
                // time bounds, and `"09:00"` is precisely the string
                // DateTime.tryParse returns null for — so this pair is what
                // makes the Dart side's time-bound parse provable against real
                // server output rather than hand-written JSON. `opens_at` also
                // turns seconds OFF against `closes_at`'s vendor default of
                // ON, so neither can stand in for a hard-coded value. P13
                // adds the step grid: `opens_at` is stepped to quarter hours,
                // `closes_at` stays at the vendor default of 1, so the golden
                // proves both halves of publish-only-when-greater-than-1.
                TimePicker::make('opens_at')
                    ->minDate('09:00')
                    ->maxDate('17:00')
                    ->minutesStep(15)
                    ->seconds(false),
                TimePicker::make('closes_at'),
                // The second wire shape, in the golden rather than only as a
                // string typed into a Dart test: `getMinDate()` is a bare
                // `evaluate()`, so a Carbon-declared bound publishes
                // "2026-01-01 09:00:00" where a string-declared one publishes
                // "09:00". Both halves of the client's bound parse now cross
                // the package boundary through a real server document — the
                // same gap Task 1 closed for date bounds.
                TimePicker::make('reminder_at')
                    ->minDate(Carbon::parse('2026-01-01 09:00')),
                // P13 Task 1: a datetime stepped on all three axes, so the
                // golden carries a real server-emitted hoursStep/
                // minutesStep/secondsStep triple for the Dart contract test
                // to parse — the same reason the bounded pair above exists.
                // Column-less like `tags`/`views`: walked, not written.
                DateTimePicker::make('booked_at')
                    ->hoursStep(2)
                    ->minutesStep(30)
                    ->secondsStep(15),
            ]),
            // P8 Task 3. A non-default format, not hex, is what makes the
            // Dart contract test parse a genuine `rgba` node out of real
            // server output rather than only ever seeing the fallback — see
            // ColorTest.php for the exhaustive (hex/hsl/rgb/rgba/nonsense/
            // exploding) unit coverage of every format this field can
            // declare, which a single golden fixture cannot exercise on its
            // own.
            ColorPicker::make('accent_color')->rgba(),
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
            // `tags_entry`, exercised here for the same reason CheckboxList
            // is above: this is the one fixture ContractSnapshotTest's
            // "exercises every component type" test walks, and Post has no
            // HasTags trait to satisfy — a `tags_entry` node never fails
            // closed the way a form `SpatieTagsInput` does (no write path to
            // protect on a read-only entry), so it publishes regardless.
            // Column-less on purpose, like `tags`/`views` above: exists to
            // be walked, not written or read against a real record.
            SpatieTagsEntry::make('labels'),
        ]);
    }
}
