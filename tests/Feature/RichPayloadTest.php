<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\RichContent;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\RecordSerializer;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RichResource;

/**
 * P6e Task 3: a rich column travels in three shapes, because three consumers
 * want three different ones — the form wants the raw string it edits, the
 * infolist wants the document, the card wants plain text. `data.<path>` keeps
 * the raw string exactly as before, and the two derived shapes ride on a flat
 * sibling `<path>.__rich`.
 *
 * The fixture markup below is deliberately non-trivial: a bold run AND a link.
 * A rich-text test whose document is empty proves nothing — `envelopeFor()`
 * returns null for an empty column and the sibling is then legitimately
 * absent, so a test seeded with '' or null would pass against a serializer
 * that never learned to publish anything at all.
 */
const RICH_BODY = '<p>Hello <strong>world</strong> and <a href="https://example.test">link</a></p>';

const RICH_TEXT = 'Hello world and link';

beforeEach(function () {
    // RichResource is not in TestCase's shared list — that list is what the
    // golden panel snapshot walks, and this slice must not move it. Registered
    // here for the duration of one test instead.
    config()->set('filament-mobile.resources', [RichResource::class]);
});

/** Every mark anywhere in the document, so "the doc is real" is asserted, not assumed. */
function marksIn(array $node): array
{
    $marks = $node['marks'] ?? [];

    foreach ($node['content'] ?? [] as $child) {
        $marks = [...$marks, ...marksIn($child)];
    }

    return $marks;
}

function richRecordPayload(Banner $banner): array
{
    return test()->actingAs(makeUser('viewer'))
        ->getJson("/api/mobile-panel/rich/{$banner->id}")
        ->assertOk()
        ->json('data');
}

it('publishes the document and the plain text beside the raw string', function () {
    $banner = seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY]);

    $payload = richRecordPayload($banner);

    // The raw string is UNCHANGED — the form prefill reads it and the write
    // path sends it back. This slice must not disturb either.
    expect($payload['body_html'])->toBe(RICH_BODY)
        ->and($payload['body_html.__rich']['doc']['type'])->toBe('doc')
        ->and($payload['body_html.__rich']['text'])->toBe(RICH_TEXT);
});

it('publishes a document that genuinely carries the markup, not an empty shell', function () {
    $banner = seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY]);

    $marks = marksIn(richRecordPayload($banner)['body_html.__rich']['doc']);

    // The link's href specifically: a bare `new Tiptap\Editor` drops the link
    // mark silently (see RichContentTest), so a document that merely parses is
    // not evidence that the conversion went through Filament's own renderer.
    expect(array_column($marks, 'type'))->toContain('bold')->toContain('link')
        ->and(array_column(array_column($marks, 'attrs'), 'href'))
        ->toContain('https://example.test');
});

it('publishes the sibling on the list endpoint too, so cards agree', function () {
    // index() and show() must not disagree about a card's text. The sibling is
    // produced by RecordSerializer, not by either controller, for this reason.
    seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY]);

    $row = $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/rich')
        ->assertOk()
        ->json('data.0');

    expect($row['body_html'])->toBe(RICH_BODY)
        ->and($row['body_html.__rich']['text'])->toBe(RICH_TEXT);
});

it('publishes no dropped markup on either endpoint, in either half of the envelope', function () {
    // The unit-level property (RichContentTest) asserted where it is actually
    // consumed. Measured before the fix: `.doc` was clean and `.text` was
    // `okalert("pwned")` — on show() AND on the index() row, which is the one
    // `resource_card.dart` renders into a card subtitle verbatim. A whitelist
    // that holds for one shape of the same payload and not the other is not a
    // whitelist.
    $banner = seedBannerWith(['name' => 'Sale', 'body_html' => '<p>ok</p><script>alert("pwned")</script>']);

    $row = $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/rich')
        ->assertOk()
        ->json('data.0');

    foreach ([richRecordPayload($banner), $row] as $payload) {
        expect($payload['body_html.__rich']['text'])->toBe('ok')
            ->and(json_encode($payload['body_html.__rich']))->not->toContain('alert');
    }
});

it('covers a prose-only entry the model declares nothing about', function () {
    // The union's second half. `prose_note` is rich because the INFOLIST said
    // ->prose(); Banner registers only `body_html`, so a rich-path set built
    // from the model alone would miss this column entirely.
    expect(RichContent::attributesFor(Banner::class))->not->toContain('prose_note');

    $banner = seedBannerWith(['name' => 'Sale', 'prose_note' => RICH_BODY]);

    expect(richRecordPayload($banner)['prose_note.__rich']['text'])->toBe(RICH_TEXT);
});

it('omits the sibling entirely when there is nothing to convert', function () {
    // Absence means unavailable. A null column gets no sibling, and every
    // consumer falls back to today's behaviour.
    $banner = seedBannerWith(['name' => 'Sale', 'body_html' => null]);

    $payload = richRecordPayload($banner);

    expect($payload)->toHaveKey('body_html')
        ->and($payload['body_html'])->toBeNull()
        ->and($payload)->not->toHaveKey('body_html.__rich');
});

it('leaves a non-rich column untouched', function () {
    $banner = seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY]);

    $payload = richRecordPayload($banner);

    expect($payload['name'])->toBe('Sale')
        ->and($payload)->not->toHaveKey('name.__rich');
});

it('gives a card no sibling for a column only one infolist entry called prose on', function () {
    // The narrowed promise, pinned. `->prose()` is a declaration about ONE
    // INFOLIST ENTRY; `HasRichContent` is a fact about the COLUMN, and only a
    // column-level fact can honestly reach a card, which is a different
    // surface no infolist entry governs. index() would have to build and walk
    // the infolist on every list request to know otherwise — a cost it exists
    // to avoid.
    //
    // So `prose_note`, bound to this card's meta slot, gets a document on
    // show() (the infolist entry asked for it) and NO sibling on index(). The
    // card renders raw markup, and `doctor` says so out loud — see
    // DoctorCommandTest. If this test ever reds because index() grew a
    // sibling, the design spec's Cards section must be corrected back.
    seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY, 'prose_note' => RICH_BODY]);

    $row = $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/rich')
        ->assertOk()
        ->json('data.0');

    expect($row['prose_note'])->toBe(RICH_BODY)
        ->and($row)->not->toHaveKey('prose_note.__rich')
        // …while the model-declared column beside it does get one, so this is
        // a narrowed promise rather than a broken list endpoint.
        ->and($row['body_html.__rich']['text'])->toBe(RICH_TEXT);
});

it('never converts a rich path whose stored value is not a string', function () {
    // A `RichEditor::json()` column stores an already-decoded ProseMirror
    // document, so the payload value is an ARRAY rather than markup.
    // `envelopeFor()` takes `?string` under `declare(strict_types=1)`, so
    // without the is_string guard this is a TypeError — a 500 on the record
    // endpoint, not a missing sibling. `options` is Banner's array-cast
    // column, which is exactly that shape.
    $banner = seedBannerWith(['name' => 'Sale', 'options' => ['type' => 'doc']]);

    $payload = (new RecordSerializer((new MobileCard())->title('name'), 'id'))
        ->withInfolistPaths(['options'])
        ->withRichPaths(['options'])
        ->serialize($banner);

    expect($payload['options'])->toBe(['type' => 'doc'])
        ->and($payload)->not->toHaveKey('options.__rich');
});

it('never derives a sibling from a column the payload does not already carry', function () {
    // The card's fieldPaths() are the serialisation whitelist, and a derived
    // shape is still the column's content: BannerResource's card names neither
    // `body_html` nor anything of it, so the list row carries no rich sibling
    // either. Deriving from the model's rich attributes alone would have
    // published this column's text on a screen that never asked for it.
    config()->set('filament-mobile.resources', [BannerResource::class]);

    seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY]);

    $row = $this->actingAs(makeUser('viewer'))
        ->getJson('/api/mobile-panel/banners')
        ->assertOk()
        ->json('data.0');

    expect($row)->not->toHaveKey('body_html')
        ->and($row)->not->toHaveKey('body_html.__rich');
});


it('converts a repeated rich value once per serializer, not once per row', function () {
    // The caching pass, measured before it was written: a 10-row index page
    // holding ONE body_html value ran 10 TipTap conversions, and a show()
    // with two rich columns sharing one string ran 2. index() serialises the
    // whole page through ONE RecordSerializer, so the memo lives there.
    $serializer = (new RecordSerializer((new MobileCard())->title('name'), 'id'))
        ->withInfolistPaths(['body_html'])
        ->withRichPaths(['body_html']);

    $sameA = seedBannerWith(['name' => 'A', 'body_html' => RICH_BODY]);
    $sameB = seedBannerWith(['name' => 'B', 'body_html' => RICH_BODY]);
    $other = seedBannerWith(['name' => 'C', 'body_html' => '<p>Other</p>']);

    // Behaviour first: every row still gets its sibling, memo or not.
    foreach ([$sameA, $sameB, $other] as $banner) {
        expect($serializer->serialize($banner)['body_html.__rich']['text'] ?? null)
            ->not->toBeNull();
    }

    // Two distinct values, two memo entries — the repeated value converted
    // once. Keyed by the raw value itself, so a second rich COLUMN holding
    // RICH_BODY would still be one entry (see RecordSerializer::$richEnvelopes).
    expect((new ReflectionProperty($serializer, 'richEnvelopes'))->getValue($serializer))
        ->toHaveCount(2);
});

it('memoises a degraded conversion too, so a bad value does not re-throw per row', function () {
    // '123' takes envelopeFor()'s JSON branch and throws inside it (pinned in
    // RichContentTest) — the catch degrades to null. A page of such rows must
    // pay that throwing path once, not per row.
    $serializer = (new RecordSerializer((new MobileCard())->title('name'), 'id'))
        ->withInfolistPaths(['body_html'])
        ->withRichPaths(['body_html']);

    $a = seedBannerWith(['name' => 'A', 'body_html' => '123']);
    $b = seedBannerWith(['name' => 'B', 'body_html' => '123']);

    expect($serializer->serialize($a))->not->toHaveKey('body_html.__rich')
        ->and($serializer->serialize($b))->not->toHaveKey('body_html.__rich');

    expect((new ReflectionProperty($serializer, 'richEnvelopes'))->getValue($serializer))
        ->toBe(['123' => null]);
});

it('shares no memo across serializers — the next request converts for itself', function () {
    // The memo is instance state BECAUSE every endpoint builds its serializer
    // per request: a static would be the worker-lifetime (Octane, Swoole)
    // question HeadlessTableHost documents this package never asking. A fresh
    // serializer — the next request's — starts empty and converts again, so
    // nothing can leak across the boundary.
    $make = fn (): RecordSerializer => (new RecordSerializer((new MobileCard())->title('name'), 'id'))
        ->withInfolistPaths(['body_html'])
        ->withRichPaths(['body_html']);

    $banner = seedBannerWith(['name' => 'Sale', 'body_html' => RICH_BODY]);

    $first = $make();
    $first->serialize($banner);

    $second = $make();

    expect((new ReflectionProperty($second, 'richEnvelopes'))->getValue($second))->toBe([])
        ->and($second->serialize($banner)['body_html.__rich']['text'])->toBe(RICH_TEXT);
});
