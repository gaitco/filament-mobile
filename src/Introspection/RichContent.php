<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Gait\MobileCore\PlainText;
use Throwable;

/**
 * A rich-text column, in the two shapes a phone needs: the structured document
 * the detail screen renders, and the plain text a one-line card shows.
 *
 * Conversion goes through Filament's OWN renderer, never a bare
 * `Tiptap\Editor`. Measured on the same input, the bare editor produces a
 * well-formed document with the link mark missing — `{"type":"text",
 * "text":" and link"}` — because it loads no Link extension. It fails
 * silently and looks correct, which is the worst combination.
 *
 * It is a DEFAULT-CONFIGURED `RichContentRenderer::make()`, not the panel
 * component's own `RichEditor::getTipTapEditor()` — which is
 * `RichContentRenderer::make()->plugins(...)->linkProtocols(...)->getEditor()`.
 * The difference is measurable and is a known weakness, documented in the
 * README: a panel that registered `myapp` for deep links keeps
 * `<a href="myapp://x">` under its own editor and loses the link mark here
 * (measured: shipped keeps `['tel:+15551234', 'https://ok.test']`, a
 * `->linkProtocols(['tel','myapp'])` renderer keeps `myapp://x`).
 *
 * It is not fixable at this seam. `linkProtocols()` is configured on the
 * `RichEditor` FORM COMPONENT and lives nowhere else — the model-declared
 * half's `RichContentAttribute::getRenderer()` carries plugins but no link
 * protocols at all — so honouring it would mean building a resource's form
 * schema for every record on `index()`, the exact per-request cost the read
 * path's narrowed `->prose()` promise exists to avoid, and it would still
 * leave the schema-declared half unconfigured.
 *
 * `ueberdosis/tiptap-php` arrives transitively through `filament/forms`; it is
 * deliberately NOT added to composer.json, which would make it a direct
 * dependency this package does not need to own.
 */
final class RichContent
{
    /**
     * The `<path>.__rich` envelope: the structured document and its plain-text
     * flattening, from ONE conversion.
     *
     * One conversion is the point, not an optimisation. The two shapes used to
     * be derived independently — the document through the whitelist, the text
     * through `strip_tags` over the RAW column — and `strip_tags` keeps a
     * `<script>` BODY. `<p>ok</p><script>alert("pwned")</script>` published a
     * correct document and the text `okalert("pwned")`, i.e. the text half
     * re-published exactly what the whitelist had just dropped, straight onto
     * a card. Flattening the converted document instead makes the whitelist
     * govern both halves by construction rather than by two code paths
     * agreeing.
     *
     * The `doc`/`text` key names are the wire contract — `contract/README.md`,
     * `contract/record-payload.json` and Dart's `RichEnvelope`. Renaming
     * either here reds the Dart golden tests.
     *
     * @return array{doc: array<string, mixed>, text: string}|null
     */
    public static function envelopeFor(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $editor = RichContentRenderer::make()->getEditor();
            $editor->setContent($raw);

            // One whitelist, every path in. Tiptap's `getContentType()` treats
            // any string that `json_decode`s cleanly as JSON and loads it
            // UNVALIDATED — `Schema::apply()` only strips marks, it never
            // filters node types — so a `->json()` column stored as a string
            // published `{"type":"evilNode","attrs":{"onclick":"alert(1)"}}`
            // and a `javascript:` href verbatim. Re-entering through the
            // serialised HTML puts the JSON path through the same DOM parser
            // that is the whitelist, so there is one sanitiser rather than two
            // doors, one of them open. Measured no-op for a legitimate JSON
            // document: the round trip is byte-identical.
            if ($editor->getContentType($raw) === 'JSON') {
                $editor->setContent($editor->getHTML());
            }

            $document = $editor->getDocument();

            // `blockSeparator: ' '` because Tiptap's TextSerializer joins
            // SIBLING TEXT NODES with the separator too, not just blocks: the
            // default "\n\n" turns one paragraph's bold run into
            // "Hello \n\nworld". A space, collapsed by PlainText, gives the
            // string a sighted web user reads. PlainText also undoes the
            // serializer's htmlspecialchars, so `a &amp; b` flattens to
            // `a & b` — the same routine, and the same result, an allowHtml()
            // option label gets.
            $text = PlainText::of($editor->getText(['blockSeparator' => ' ']));
        } catch (Throwable) {
            // Degrades its own value, never the request. Without a document
            // the client falls back to the raw string it already has.
            //
            // Not hypothetical: a column holding the literal text "123" is
            // handed to the JSON branch and throws when the schema tries to
            // array-index an int. Measured, not assumed — see RichContentTest.
            return null;
        }

        return is_array($document) ? ['doc' => $document, 'text' => $text] : null;
    }

    /**
     * The names of the entries a walked tree published as `rich_entry` — the
     * SCHEMA's half of what counts as rich, i.e. an entry `->prose()` was
     * called on (the model-declared half is attributesFor() below).
     *
     * Two callers need exactly this: `MobilePanelController::show()`, to tell
     * the serializer which paths to convert, and `DoctorCommand`, to name a
     * card bound to a column only an entry called prose on. One
     * implementation, so the two cannot drift — the same reason `PlainText`
     * exists.
     *
     * Recurses into layout children: an entry inside a `Section` is no less
     * rich for being nested, and that is the common real layout.
     *
     * @param  list<array<string, mixed>>  $nodes  a walked tree, not components
     * @return list<string>
     */
    public static function entryNamesIn(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'rich_entry' && is_string($node['name'] ?? null)) {
                $names[] = $node['name'];
            }

            $names = [...$names, ...self::entryNamesIn($node['children'] ?? [])];
        }

        return $names;
    }

    /**
     * Attribute names `$modelClass` declares as rich content.
     *
     * `HasRichContent` — the interface — guarantees only three methods:
     * `getRichContentAttribute()`, `renderRichContent()`, and the singular
     * predicate `hasRichContentAttribute()`. The plural accessor this method
     * relies on, `getRichContentAttributes()`, is NOT part of that interface;
     * it lives on `InteractsWithRichContent`, the concern Filament's own docs
     * pair with the interface to implement it. Its presence is therefore
     * checked with `method_exists()` rather than assumed from the interface
     * alone.
     *
     * Its return shape was also assumed wrong once: measured, it is
     * `array<string, RichContentAttribute>` KEYED by attribute name, not a
     * list of name strings. `array_keys()`, not the values, is the name list
     * — filtering the values for strings (as a first draft of this method
     * did) silently returns `[]` for every model, always, because the values
     * are objects.
     *
     * @return array<int, string>
     */
    public static function attributesFor(string $modelClass): array
    {
        if (! is_a($modelClass, HasRichContent::class, true)) {
            return [];
        }

        try {
            $model = new $modelClass();

            if (! method_exists($model, 'getRichContentAttributes')) {
                return [];
            }

            return array_keys($model->getRichContentAttributes());
        } catch (Throwable) {
            return [];
        }
    }
}
