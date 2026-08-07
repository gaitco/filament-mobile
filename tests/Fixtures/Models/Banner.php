<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Banner extends Model implements HasRichContent
{
    use InteractsWithRichContent;

    protected $guarded = [];

    /**
     * The first cast exists for one reason: raw and cast
     * forms of `options` differ observably inside a schema closure (a JSON
     * string vs an array), so a form seeded from `getAttributes()` behaves
     * differently from one seeded from `attributesToArray()`.
     *
     * `caption` is the second, and duller: a JSON column two Hidden fields
     * address as `caption.ar` and `caption.en`, so a default written under a
     * literal dotted key is observable as a missing column rather than a pass.
     *
     * `plain_multi` is the third: a LIST-valued default, which is the one shape
     * a recursive merge corrupts by index rather than replacing.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'options' => 'array',
        'caption' => 'array',
        'plain_multi' => 'array',
        // P6c: the JSON-column repeaters. A repeater's value is a list of
        // maps, and the array cast is what lets seedBannerWith() write one
        // directly rather than a pre-encoded JSON string.
        'line_items' => 'array',
        'fixed_rows' => 'array',
        'exploding_repeater' => 'array',
        'locked_rows' => 'array',
        'guarded_rows' => 'array',
        'relation_rows' => 'array',
        // P7 Task 2: a TagsInput's value is a list on the wire and, with no
        // separator, a list in the column too.
        //
        // `separated_labels` is deliberately ABSENT here (P7 Task 3): its
        // column is `text` holding "a,b", the shape Filament's own
        // `dehydrateStateUsing` writes, and the array cast is what would make
        // every assertion about that shape vacuous.
        'labels' => 'array',
        'exploding_labels' => 'array',
        // P7 Task 4: a KeyValue's value is a Map<String, String> on the wire,
        // and the array cast is what lets seedBannerWith() write one
        // directly. `restricted_meta` needs two pairs so a flat-state bug —
        // editing pair 2 leaking into pair 1 — has somewhere to show up.
        'meta' => 'array',
        'restricted_meta' => 'array',
        'restricted_meta_2' => 'array',
        'exploding_meta' => 'array',
    ];

    /**
     * The model half of rich-content detection: `body_html` is the same
     * column `BannerResource` already edits with `RichEditor::make('body_html')`
     * (see its comment on the MySQL 1364 blocker `RichEditor` unmapped
     * caused). Registering it here is what lets `RichContent::attributesFor()`
     * and, later, `TextEntry::isProse()`'s model-declared half answer true
     * for it without a `->prose()` call on the infolist entry.
     */
    protected function setUpRichContent(): void
    {
        $this->registerRichContent('body_html');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Minimal by design: this fixture exists to be introspected by
     * FieldPersistence, not exercised by a write test — the write path never
     * calls saveRelationships(), so a pivot row is never expected here.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
