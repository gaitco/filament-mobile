<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * P6c Task 3b: `show()`'s read path for a repeater. Inserted after Task 3's
 * review found the gap: `MobilePanelController::leafNames()` treated a
 * repeater node — which carries BOTH `children` (its item template) AND its
 * own writable name — as a pass-through container, so `formPaths()` never
 * collected the repeater's own name and `GET /banners/{id}` answered with no
 * `line_items` key at all. An edit screen built on that response renders zero
 * rows for a record that has them, and submitting that empty state back would
 * silently overwrite the stored rows (RepeaterWriteTest already proves the
 * write path itself round-trips correctly — this file is the read half).
 */
it('returns a repeater column\'s stored rows in the record payload', function () {
    // Assertion 1.
    $rows = [['sku' => 'A', 'qty' => 1], ['sku' => 'B', 'qty' => 2]];
    $banner = seedBannerWith(['name' => 'Original', 'body_html' => '<p>Body</p>', 'line_items' => $rows]);

    $data = test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('line_items')
        ->and($data['line_items'])->toBeArray()
        // keySorted: MySQL's json type returns object keys sorted (see Pest.php).
        ->and(keySorted($data['line_items']))->toBe(keySorted($rows));
});

it('returns a disabled repeater\'s stored rows too — disabled means not writable, not absent', function () {
    // Pins a judgement call the review verified but the suite didn't yet
    // guard: `leafNames()` has never checked `disabled` for any leaf type
    // (an ordinary disabled field like `bypass_note` is still serialized on
    // GET), so a disabled repeater follows the same rule rather than being
    // carved out like a relationship repeater is. `locked_rows` — disabled(),
    // NOT ->relationship() — is a real column with real stored data that an
    // edit screen should still be able to show, just not submit.
    $rows = [['note' => 'kept']];
    $banner = seedBannerWith(['name' => 'Original', 'body_html' => '<p>Body</p>', 'locked_rows' => $rows]);

    $data = test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('locked_rows')
        ->and($data['locked_rows'])->toBe($rows);
});

it('answers a null repeater column the same way every other null column is answered', function () {
    // Assertion 2. RecordSerializer::serialize() sets every declared path
    // unconditionally via data_set()/direct assignment — there is no
    // omit-when-null branch anywhere in it — so the existing convention for
    // EVERY column (card, infolist, or form) is "key present, value null".
    // A repeater column follows the same rule rather than inventing its own.
    $banner = seedBannerWith(['name' => 'Original', 'body_html' => '<p>Body</p>', 'line_items' => null]);

    $data = test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect(array_key_exists('line_items', $data))->toBeTrue()
        ->and($data['line_items'])->toBeNull();
});

it('publishes a relationship repeater\'s rows off the relationship, never as a column', function () {
    // Assertion 3, INVERTED — and the inversion is a bug fix, not a change of
    // mind. `tag_rows` is `Repeater::relationship('tags')`: it has no column,
    // so the attribute pass must still not read one (data_get() would resolve
    // the RELATION and publish whole child models past the card's whitelist).
    // But withholding the key entirely, once P9 made the field WRITABLE, is
    // what destroyed data: the client seeded the field to null, submitted that
    // null, and the write path read it as "clear every row". So the rows are
    // published by a pass of their own — off the relationship, projected onto
    // the item template's fields — and the attribute pass still skips the
    // name. See MobilePanelController::repeaterRelationRows() and the null
    // case in RepeaterWriteTest.
    //
    // Zero rows publish `[]`, not absence: "no rows" is a real answer, and a
    // client that could not tell it from "unknown" is back to guessing.
    $banner = seedBanner();

    $data = test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('tag_rows')
        ->and($data['tag_rows'])->toBe([]);
});

it('closes the round trip: GET the rows, PUT them back unchanged, GET again, identical', function () {
    // Assertion 4 — the one that would have caught the gap in the first
    // place. Before the fix, step 1 already returns no `line_items` key, so
    // an edit screen built from it has nothing to submit back.
    $rows = [['sku' => 'A', 'qty' => 1]];
    $banner = seedBannerWith(['name' => 'Original', 'body_html' => '<p>Body</p>', 'line_items' => $rows]);
    $user = makeUser('admin');

    $first = test()->actingAs($user)
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect(keySorted($first['line_items']))->toBe(keySorted($rows));

    test()->actingAs($user)
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $first['name'],
            'body_html' => '<p>Body</p>',
            'line_items' => $first['line_items'],
        ])
        ->assertOk();

    $second = test()->actingAs($user)
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect(keySorted($second['line_items']))->toBe(keySorted($rows));
});
