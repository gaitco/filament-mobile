<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Gait\FilamentMobile\Introspection\RichContent;
use Gait\FilamentMobile\Introspection\TagSeparators;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Turns a record into exactly the payload the card declared, and nothing more.
 *
 * The card's fieldPaths() is the whitelist: a column no screen displays never
 * reaches the phone, so adding a secret column to a table cannot leak it. The
 * corollary is deliberate — a field missing from the card is absent from every
 * record, which is the opt-in property, not a bug.
 */
final class RecordSerializer
{
    /** @var list<string> */
    private array $infolistPaths = [];

    /** @var list<string> */
    private array $formPaths = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $repeaterRows = [];

    /** @var list<string> */
    private array $richPaths = [];

    /**
     * Resolved once per instance, on first use — `index()` serialises a whole
     * page through one serializer and must not rebuild the resource's form per
     * record. Null means "not resolved yet", `[]` means "resolved, none".
     *
     * @var array<string, string>|null
     */
    private ?array $tagSeparators = null;

    /**
     * Rich-text envelopes this instance has already produced, keyed by the
     * raw column value itself: one TipTap conversion per distinct value per
     * serializer, never one per row.
     *
     * `index()` serialises a whole page through ONE serializer (the same
     * property `$tagSeparators` above exists for), and a shared or defaulted
     * rich column — one body stamped onto every row — otherwise pays the
     * full parse per row: measured 10 conversions for a 10-row page holding
     * a single value, 1 after. `show()` gains the same way when two rich
     * columns hold one string (2 measured, 1 after).
     *
     * An instance property, not a static: every endpoint builds its
     * serializer per request, so the memo's lifetime IS the request and the
     * worker-lifetime static question (Octane, Swoole) never has to be
     * answered — the same ruling `HeadlessTableHost` makes for its host.
     *
     * The key is the raw string, not a hash of it: a hash collision here
     * would publish one value's document under another's — a wrong answer,
     * not a slow one — and PHP hashes string keys internally anyway. The
     * column name is deliberately NOT part of the key: `envelopeFor()` is a
     * pure function of the raw string, so two columns holding one value are
     * one conversion, and naming the column would only split that entry.
     * Null results are memoised too — a value whose conversion degrades (see
     * RichContent) must not re-run its throwing path for every row of a page.
     *
     * @var array<string, array{doc: array<string, mixed>, text: string}|null>
     */
    private array $richEnvelopes = [];

    /**
     * @param  class-string|null  $resourceClass  the resource whose form owns
     *                                            this record's field shapes.
     *                                            Null for a related record
     *                                            (RelationController), which
     *                                            has a card but no form.
     */
    public function __construct(
        private readonly MobileCard $card,
        private readonly string $recordKey,
        private readonly ?string $resourceClass = null,
    ) {
    }

    /**
     * The detail screen renders the infolist, which routinely names fields the
     * list card never shows. Widening is additive and still a whitelist: only
     * paths some declared screen actually references are ever serialised.
     *
     * Returns a copy so one serializer cannot silently widen another's payload
     * — the list endpoint's card-only instance stays card-only.
     *
     * @param  list<string>  $paths
     */
    public function withInfolistPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->infolistPaths = array_values($paths);

        return $clone;
    }

    /**
     * Form-field paths, serialised under their LITERAL key.
     *
     * A translatable field is published as `caption.ar`, and Laravel validates
     * that as a PATH — `caption[ar]`. But an infolist entry asks for `caption`,
     * Spatie's accessor, which is the current locale's string. They are
     * different things sharing one JSON key, and `data_set('caption.ar', …)`
     * replaces the scalar with a map: the endpoint answered
     * `{"caption": {"ar": null, "en": null}}` from the moment the record
     * payload was widened with the form's fields.
     *
     * Neither representation can be dropped — without the scalar the infolist
     * has nothing to render, without the per-locale keys the edit form opens
     * blank — and ordering is not a fix, because whichever writes last wins.
     * So form paths get a flat key of their own, and the client reads a
     * literal key before it tries to traverse.
     *
     * @param  list<string>  $paths
     */
    public function withFormPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->formPaths = array_values($paths);

        return $clone;
    }

    /**
     * A relationship repeater's rows, already read and PROJECTED onto the item
     * template's fields by the caller (see
     * MobilePanelController::repeaterRelationRows()).
     *
     * Handed in rather than read here, because this class reads attributes off
     * a record and these are child records off a relationship — a different
     * question, needing the form components to know which fields may travel.
     * Absent means the field opens empty; `[]` means it genuinely has no rows.
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    public function withRepeaterRows(array $rows): self
    {
        $clone = clone $this;
        $clone->repeaterRows = $rows;

        return $clone;
    }

    /**
     * Rich-text paths the SCHEMA declared — the infolist entries the walker
     * refined to `rich_entry` because `->prose()` was set on them.
     *
     * Additive to what the record's own model declares (see richPathsFor()),
     * never a replacement: an entry may be prose without the model knowing,
     * and a model may declare an attribute no infolist mentions.
     *
     * @param  list<string>  $paths
     */
    public function withRichPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->richPaths = array_values($paths);

        return $clone;
    }

    /** @return array<string, mixed> */
    public function serialize(Model $record): array
    {
        // The key is not a card field, but the client needs it to route to the
        // record endpoint, so it is always present.
        $payload = [$this->recordKey => data_get($record, $this->recordKey)];

        $paths = array_unique([...$this->card->fieldPaths(), ...$this->infolistPaths]);

        foreach ($paths as $path) {
            // data_get()/data_set() give dotted paths their nesting for free:
            // `company.name` reads through the eager-loaded relation and
            // writes {"company":{"name":…}}. A null anywhere along the path
            // yields null — a missing relation is never a failure.
            data_set($payload, $path, $this->read($record, $path));
        }

        // AFTER the nesting pass, and written flat: a form path that shares its
        // first segment with a card or infolist path must not overwrite it, and
        // must not be overwritten either. `caption` stays the infolist's
        // scalar; `caption.ar` sits beside it as its own key.
        foreach ($this->formPaths as $path) {
            // Flat for every form path. For an undotted name this is exactly
            // what data_set() would have written anyway; for a dotted one it
            // is the whole point — `caption.ar` becomes its own key instead of
            // nesting over the infolist's `caption` scalar.
            //
            // A form path that collides with an already-written key does not
            // overwrite it: `caption` (infolist) and `caption.ar` (form) are
            // different keys, so both survive.
            $payload[$path] = $this->read($record, $path);
        }

        // Flat, and after the form pass, for the same reason `caption.ar` is:
        // a relationship repeater's name is a form field's name, and its rows
        // are what the edit screen seeds that field from. Never through
        // read() — there is no attribute to read; the rows arrived already
        // projected, off the relationship.
        foreach ($this->repeaterRows as $name => $rows) {
            $payload[$name] = $rows;
        }

        // LAST, and flat, exactly like a translatable's `caption.ar`. Three
        // consumers want three shapes of one rich column — the card wants
        // plain text, the infolist wants the document, the form wants the raw
        // string it edits — and an undotted name can only carry one. The
        // obvious seam does not work, measured: withInfolistPaths(['body'])
        // plus withFormPaths(['body']) yields ONE key, because the form pass
        // writes flat and runs last. So `data.<path>` keeps the raw string
        // byte-for-byte and the two derived shapes ride on a sibling key.
        //
        // Read out of the PAYLOAD, never off the record, and that is the whole
        // whitelist guarantee: a derived shape is still the column's content,
        // so a rich column no screen declared has nothing here to derive from
        // and gets no sibling. `Arr::get()` prefers a literal key before it
        // traverses, so it finds both what the flat form pass wrote and what
        // the nested infolist pass did.
        //
        // Absence means unavailable: nothing to convert (null, empty, or a
        // conversion that throws) means no sibling at all, never an empty one,
        // and every consumer falls back to the raw string it already has.
        foreach ($this->richPathsFor($record) as $path) {
            $raw = Arr::get($payload, $path);
            $envelope = is_string($raw) ? $this->richEnvelope($raw) : null;

            if ($envelope === null) {
                continue;
            }

            // Both shapes come out of ONE conversion, so the whitelist that
            // builds the document also governs the text — see
            // RichContent::envelopeFor(). Deriving the text here from `$raw`
            // instead (`strip_tags`, which keeps a `<script>` body) is the
            // defect that made this one call.
            $payload["{$path}.__rich"] = $envelope;
        }

        // LAST, and here rather than in a controller, for the reason the
        // `__rich` sibling above is produced here: every endpoint serialises
        // through this class, so this is the only place `index()` and `show()`
        // cannot disagree about the same column. A separator-configured
        // TagsInput stores the panel's delimited string (see TagSeparators,
        // whose dehydrate() is the write half), and the contract says the wire
        // value is a List<String> in EVERY case — which is only true if every
        // seam that publishes the column splits it. It was wired at show()
        // alone in the first cut of P7 Task 3, and a card listing such a field
        // published `"a,b"` from index() and `["a","b"]` from show().
        return TagSeparators::hydrate($payload, $this->tagSeparators());
    }

    /** @return array<string, string> */
    private function tagSeparators(): array
    {
        return $this->tagSeparators ??= $this->resourceClass === null
            ? []
            : TagSeparators::forResource($this->resourceClass);
    }

    /**
     * `RichContent::envelopeFor()` through this instance's memo — see
     * `$richEnvelopes` for why the cache lives here and is keyed the way it
     * is. `array_key_exists`, not `??=`: a memoised null (a value whose
     * conversion degraded) must be returned, not recomputed.
     *
     * @return array{doc: array<string, mixed>, text: string}|null
     */
    private function richEnvelope(string $raw): ?array
    {
        if (! array_key_exists($raw, $this->richEnvelopes)) {
            $this->richEnvelopes[$raw] = RichContent::envelopeFor($raw);
        }

        return $this->richEnvelopes[$raw];
    }

    /**
     * The union, in ONE place — P6d shipped a defect because the same rule
     * lived in two.
     *
     * Half of it is the record's own model (`HasRichContent` plus the
     * `InteractsWithRichContent` concern), which is why this is resolved here
     * rather than passed in: every endpoint serialises through this class, so
     * `index()`, `show()`, `store()` and `update()` cannot disagree about a
     * card's text without any of them wiring anything up. The other half is
     * whatever the schema declared with `->prose()`, which only the walker can
     * know — see withRichPaths().
     *
     * @return list<string>
     */
    private function richPathsFor(Model $record): array
    {
        return array_values(array_unique([
            ...RichContent::attributesFor($record::class),
            ...$this->richPaths,
        ]));
    }

    /**
     * `data_get($record, 'title.ar')` cannot reach a translation: the first
     * segment resolves through the accessor, which hands back the *current
     * locale's string*, and reading `ar` off a string is null. So every
     * translatable field serialised as null — the card showed the right text
     * (its path is undotted) while every edit form opened blank, and saving
     * that blank form would have written the blanks back.
     *
     * `method_exists` rather than a dependency: Spatie's `HasTranslations` is
     * what Filament's own translatable plugin builds on, and a model without
     * it falls through to the ordinary path unchanged.
     */
    private function read(Model $record, string $path): mixed
    {
        $value = data_get($record, $path);

        if ($value !== null || ! str_contains($path, '.')) {
            return $value;
        }

        [$attribute, $rest] = explode('.', $path, 2);

        if (! method_exists($record, 'getTranslations')
            || ! method_exists($record, 'getTranslatableAttributes')
            || ! in_array($attribute, $record->getTranslatableAttributes(), true)) {
            return null;
        }

        return data_get($record->getTranslations($attribute), $rest);
    }
}
