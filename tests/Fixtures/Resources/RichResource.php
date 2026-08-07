<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use RuntimeException;

/**
 * Exercises SchemaWalker::isRich() — the two halves of Filament's own
 * isProse() (design spec, Task 2) — against a model with genuine rich-content
 * data, not an empty stand-in.
 *
 * The model is `Banner`, not a fresh fixture: `Banner::setUpRichContent()`
 * already registers `body_html` (Task 1, see RichContentTest), so
 * `RichContent::attributesFor(Banner::class) === ['body_html']` is a fact
 * this resource inherits rather than one it has to re-establish. Naming the
 * model-declared field `body_html` (not `body`, the brief's placeholder) is
 * what makes the "leaves an ordinary text entry alone" and "model-declared"
 * tests run against the same fixture's real data instead of a name that
 * happens to match nothing.
 *
 * Not in the shared fixture list in `TestCase` — RichSchemaTest walks it
 * directly, so it never reaches the golden panel snapshot.
 */
class RichResource extends Resource
{
    protected static ?string $model = Banner::class;

    // Explicit, so the endpoints under test are `/rich` and `/rich/{id}`
    // rather than whatever Filament's pluraliser makes of "Rich".
    protected static ?string $slug = 'rich';

    /**
     * The card binds a rich column of each kind, deliberately:
     *
     * - `body_html` is rich because the MODEL says so, which is a fact about
     *   the column. The list endpoint publishes `body_html.__rich` and the
     *   card renders clean text.
     * - `prose_note` is rich only because ONE INFOLIST ENTRY calls `->prose()`,
     *   which is a fact about that entry and governs no other surface. The
     *   list endpoint publishes no sibling for it and the card renders raw
     *   markup — a supported-but-unhelpful combination `doctor` names, and
     *   which RichPayloadTest pins.
     */
    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('name')
                ->subtitle('body_html')
                ->meta('prose_note'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            // Half 1: ->prose() set explicitly, no model participation needed.
            TextEntry::make('prose_body')->prose(),
            // Half 1 again, but over a REAL column, so the record payload has
            // something to convert: Banner declares only `body_html` rich, so
            // this is the entry that proves the serializer's rich paths are a
            // union with the walker's rich_entry names rather than the model's
            // list alone.
            //
            // NESTED, and that is the point: a rich entry inside a Section is
            // the common real layout, and both readers of the walked tree
            // (RichContent::entryNamesIn(), and leafNames() beside it) recurse
            // into layout children. Flat, that recursion was dead code the
            // suite could not fail on — deleting it left 7/7 green.
            Section::make('Nested')->schema([
                TextEntry::make('prose_note')->prose(),
            ]),
            // Half 2: no ->prose() call — the model alone declares this rich.
            TextEntry::make('body_html'),
            // Neither half holds: ordinary text.
            TextEntry::make('name'),
            // Half 1's gate throws. Refusal, not upgrade: stays text_entry.
            TextEntry::make('exploding_body')->prose(fn () => throw new RuntimeException('boom')),
        ]);
    }
}
