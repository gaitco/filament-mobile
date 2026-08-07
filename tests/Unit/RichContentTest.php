<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\RichContent;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

it('converts html to a prosemirror document, keeping link marks', function () {
    // The trap this class exists to avoid: a bare `new Tiptap\Editor` produces
    // a well-formed document with the link mark SILENTLY MISSING —
    // {"type":"text","text":" and link"}. Filament's own RichContentRenderer
    // keeps it.
    $doc = RichContent::envelopeFor('<p>Hi <a href="https://x.test">link</a></p>')['doc'];

    $marks = $doc['content'][0]['content'][1]['marks'] ?? [];

    expect($doc['type'])->toBe('doc')
        ->and($marks[0]['type'])->toBe('link')
        ->and($marks[0]['attrs']['href'])->toBe('https://x.test');
});

it('drops executable markup from BOTH halves, because the whitelist is the sanitiser', function () {
    // Not a feature bolted on — a consequence of converting through TipTap's
    // extension whitelist. It needs its own test precisely because nothing in
    // the code says "sanitise".
    //
    // BOTH halves, and that is the whole point of the assertion. Asserting it
    // on `doc` alone passed against a serializer that flattened `text` from
    // the RAW column with `strip_tags` — which removes tags but KEEPS a
    // `<script>` body — so `text` was `okalert("pwned")` while `doc` was
    // clean, and the card renders `text`. Same payload, opposite properties.
    $envelope = RichContent::envelopeFor('<p>ok</p><script>alert("pwned")</script>');

    expect(json_encode($envelope))->not->toContain('alert')
        ->and(json_encode($envelope))->not->toContain('script')
        ->and($envelope['text'])->toBe('ok');
});

it('runs an already-JSON column through the same whitelist as HTML', function () {
    // Tiptap's `getContentType()` treats any string that `json_decode`s
    // cleanly as JSON and loads it UNVALIDATED — `Schema::apply()` strips
    // marks and never filters node types. Measured before the fix: this
    // document was published verbatim, `evilNode`, `onclick` and the
    // `javascript:` href intact, on a path the HTML input has always had
    // filtered. `href` is the one attr Dart hands to a host's `onLinkTap`.
    $envelope = RichContent::envelopeFor('{"type":"doc","content":[{"type":"evilNode","attrs":{"onclick":"alert(1)"},"content":[{"type":"text","text":"pwn","marks":[{"type":"link","attrs":{"href":"javascript:alert(1)"}}]}]}]}');

    expect(json_encode($envelope))->not->toContain('evilNode')
        ->and(json_encode($envelope))->not->toContain('onclick')
        ->and(json_encode($envelope))->not->toContain('javascript:')
        ->and($envelope['text'])->toBe('pwn');
});

it('leaves a legitimate JSON document intact through that same round trip', function () {
    // The cost of the guard above, measured rather than assumed: a `->json()`
    // column holding a real document must survive re-entry through the
    // serialised HTML unchanged, marks and all.
    $html = '<p>Hello <strong>world</strong> and <a href="https://example.test">link</a></p>';

    $fromHtml = RichContent::envelopeFor($html);
    $fromJson = RichContent::envelopeFor(json_encode($fromHtml['doc']));

    expect($fromJson)->toBe($fromHtml)
        ->and($fromJson['text'])->toBe('Hello world and link');
});

it('flattens to plain text for a card', function () {
    // Flattened from the DOCUMENT. `blockSeparator` is a space because
    // Tiptap's TextSerializer joins sibling text nodes with it too, so the
    // default "\n\n" would split one paragraph's bold run; PlainText then
    // collapses the whitespace and undoes the serializer's escaping.
    expect(RichContent::envelopeFor('<p>Hello  <strong>world</strong></p>')['text'])
        ->toBe('Hello world')
        ->and(RichContent::envelopeFor('<p>a &amp; b</p>')['text'])->toBe('a & b');
});

it('returns null for nothing to convert', function () {
    expect(RichContent::envelopeFor(null))->toBeNull()
        ->and(RichContent::envelopeFor(''))->toBeNull();
});

it('degrades to null when conversion throws, never the request', function () {
    // Verified, not hypothetical: Tiptap's own `getContentType()` treats any
    // string `json_decode`s cleanly as JSON rather than HTML. "123" decodes
    // to the int 123, and the schema then throws trying to array-index it —
    // `ErrorException: Trying to access array offset on int`. A plausible
    // real case (a corrupted or wrongly-imported column) reaches exactly this
    // path, and envelopeFor() must degrade rather than propagate.
    expect(RichContent::envelopeFor('123'))->toBeNull();
});

it('lists a model-declared rich attribute by name', function () {
    // Guards the fix over the plan's first draft: getRichContentAttributes()
    // returns array<string, RichContentAttribute> KEYED by name, not a list
    // of name strings. array_filter(..., 'is_string') over that array keeps
    // nothing — every value is an object — and silently returns [] for every
    // model. This assertion fails against that draft and passes against
    // array_keys().
    expect(RichContent::attributesFor(Banner::class))->toBe(['body_html']);
});

it('returns no rich attributes for a model that does not implement HasRichContent', function () {
    expect(RichContent::attributesFor(Company::class))->toBe([]);
});
