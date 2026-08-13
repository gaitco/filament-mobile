<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\RichResource;

/**
 * Golden file for a real RECORD payload, mirroring what ContractSnapshotTest
 * does for `/schema` and DashboardSnapshotTest does for `/dashboard`: this is
 * what the real endpoint actually emits for a real record with real rich
 * content, read by the Dart contract test. Regenerate with UPDATE_SNAPSHOTS=1.
 *
 * This is the test Task 7's Step 0 exists to add. The `__rich` envelope key
 * `'doc'` was hardcoded independently on both sides — RecordSerializer.php
 * and rich_document_test.dart's `bannerBodyHtmlRich` constant, hand-typed
 * from running the real conversion once and pasting the result — with
 * nothing that reads a real record payload through the real endpoint. A
 * coordinated rename of the key on both sides would have kept both suites
 * green and broken the wire, the exact "both sides internally consistent,
 * jointly wrong" failure this project has already shipped twice. This golden
 * is what makes that rename fail loudly instead, once the Dart constant
 * reads this file rather than its own hand-typed copy.
 *
 * Through the real endpoint, not RecordSerializer directly, for the same
 * reason DashboardSnapshotTest goes through DashboardController rather than
 * WidgetReader: MobilePanelController::show() resolves the record, the
 * infolist's rich paths and the permissions/actions envelope around the
 * serialized data — a fixture built by hand-wrapping RecordSerializer would
 * pin the serializer's shape while leaving the endpoint's envelope unchecked.
 */

it('matches the committed record-payload contract snapshot', function () {
    // RichResource is not in TestCase's shared list (see its own docblock) —
    // that list is what the golden PANEL snapshot walks, and this slice must
    // not move it. Registered here for the duration of this test only, same
    // idiom RichPayloadTest already uses.
    config()->set('filament-mobile.resources', [RichResource::class]);

    // The same non-trivial markup RichPayloadTest's RICH_BODY uses — a bold
    // run AND a link. A rich-text fixture whose document is empty proves
    // nothing: envelopeFor() returns null for an empty column and the
    // sibling is then legitimately absent, so a test seeded with '' or null
    // would pass against a serializer that never learned to publish anything.
    $banner = seedBannerWith([
        'name' => 'Sale',
        'body_html' => '<p>Hello <strong>world</strong> and <a href="https://example.test">link</a></p>',
        // A3: repeater rows on the record payload — two rows, so the golden
        // pins the list-of-maps shape a repeater's value actually travels as.
        'line_items' => [
            ['sku' => 'A-1', 'qty' => 2],
            ['sku' => 'B-2', 'qty' => 5],
        ],
    ]);

    $body = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/rich/{$banner->id}")
        ->assertOk()
        ->json();

    $json = json_encode(
        $body,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";

    if (getenv('UPDATE_SNAPSHOTS') === '1') {
        file_put_contents(contractPath('record-payload.json'), $json);
    }

    // Compared as STRUCTURE, not bytes. The golden is generated on SQLite and
    // read by the Dart contract test, but `line_items` comes back through a
    // json column, and MySQL returns object keys sorted — so a byte compare
    // pinned the driver's key order as though it were the contract. It is not:
    // a JSON object is unordered and the client reads every value by name.
    //
    // What this test exists to catch survives intact, because keySorted()
    // changes only ordering: a renamed key (the `__rich` envelope's `doc`,
    // hand-typed on both sides — see the docblock), a dropped key, a changed
    // value or a changed type all still fail. Only "the keys came back in a
    // different order" stops being a failure. The file is still written
    // pretty-printed under UPDATE_SNAPSHOTS=1, so it stays readable as
    // documentation.
    expect(contractPath('record-payload.json'))->toBeReadableFile()
        ->and(keySorted(json_decode($json, true)))
        ->toBe(keySorted(json_decode(file_get_contents(contractPath('record-payload.json')), true)));
});
