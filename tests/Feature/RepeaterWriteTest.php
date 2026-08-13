<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;

/**
 * P6c Task 3: the write path round-trips a JSON-column repeater's rows
 * through store()/update() — the value is an ordinary attribute
 * (`line_items` is `array`-cast) and Laravel's validated payload carries the
 * whole array for `line_items.*` rules (verified empirically before the plan
 * was written — see the design spec). P9 added the two `tag_rows` cases: a
 * RELATIONSHIP repeater is not an attribute at all, and its rows write
 * through the relation pass instead.
 *
 * Assertions read the record directly (`->fresh()->line_items`), the same
 * convention WriteEndpointTest already uses for every column check —
 * deliberately not through the show() endpoint's `data` payload, whose
 * repeater coverage lives in RepeaterReadTest.
 */
it('persists submitted rows on update and reads them back identically', function () {
    // Assertion 1.
    $banner = seedBanner();
    $rows = [['sku' => 'A', 'qty' => 1]];

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'line_items' => $rows,
        ])
        ->assertOk();

    expect(keySorted($banner->fresh()->line_items))->toBe(keySorted($rows));
});

it('422s with an error key shaped exactly line_items.0.sku when a row fails a child rule', function () {
    // Assertion 2.
    $banner = seedBanner();

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'line_items' => [['sku' => '', 'qty' => 1]],
        ])
        ->assertStatus(422);

    // The exact key, not a bare substring: this is what the client renders
    // the error against.
    expect(array_keys($response->json('errors')))->toContain('line_items.0.sku');

    expect($banner->fresh()->line_items)->toBeNull();
});

it('422s when fewer rows than minItems are submitted', function () {
    // Assertion 3.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'line_items' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['line_items']);

    expect($banner->fresh()->line_items)->toBeNull();
});

it('422s when more rows than maxItems are submitted', function () {
    // Assertion 3, the other bound.
    $banner = seedBanner();

    $rows = array_map(
        fn (int $i): array => ['sku' => "SKU{$i}", 'qty' => $i],
        range(1, 6),
    );

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'line_items' => $rows,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['line_items']);

    expect($banner->fresh()->line_items)->toBeNull();
});

it('silently drops a disabled repeater\'s submitted rows, writing nothing', function () {
    // Assertion 4. `locked_rows` — genuinely disabled(), distinct from
    // `fixed_rows` (addable/deletable false but not itself disabled).
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'locked_rows' => [['note' => 'sneaky']],
        ])
        ->assertOk();

    expect($banner->fresh()->locked_rows)->toBeNull();
});

it('writes a relationship repeater\'s rows through the relation pass (P9)', function () {
    // Assertion 5, inverted by P9. `tag_rows` — Repeater::relationship('tags')
    // — has no column of its own; its rows are child records. Before P9 the
    // submitted rows were silently dropped behind a 200 and the field was
    // published read-only; now the controller's relation pass calls
    // saveRelationships() on it (the same machinery `tag_ids` above has used
    // since P3b), and Filament's own Repeater::saveToRelationship() writes
    // the rows. Verified empirically against vendor before this shipped.
    $banner = seedBanner('Rows');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Rows',
            'body_html' => '<p>Body</p>',
            'tag_rows' => [['name' => 'row one'], ['name' => 'row two']],
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->pluck('tags.name')->all())
        ->toBe(['row one', 'row two']);
});

it('does not destroy a relationship repeater\'s rows when the payload carries a null', function () {
    // The regression this pins is silent DATA LOSS, and it shipped green:
    // the record payload withheld a relationship repeater's rows while the
    // schema published the field writable, so the client seeded it to null,
    // sent that null, and `Arr::has()` read a present null as a deliberate
    // clear. Editing a banner's NAME deleted every one of its tags, behind a
    // 200. Both halves are fixed and both are pinned — the rows are published
    // (the test below) and a null is no longer a clear (this one).
    $banner = seedBanner('Rows');
    $banner->tags()->createMany([['name' => 'keep one'], ['name' => 'keep two']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Renamed only',
            'body_html' => '<p>Body</p>',
            'tag_rows' => null,
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->pluck('tags.name')->all())
        ->toBe(['keep one', 'keep two']);
});

it('publishes a relationship repeater\'s rows, projected onto the item template', function () {
    // The other half: without this the client has nothing to seed the field
    // from, and a writable field with no value is what produced the null
    // above. Projected, not whole models — `id`, `created_at` and the pivot
    // are the record's business, not the wire's, and the card/infolist passes
    // hold to the same whitelist.
    $banner = seedBanner('Rows');
    $banner->tags()->createMany([['name' => 'keep one'], ['name' => 'keep two']]);

    $rows = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data.tag_rows');

    expect($rows)->toBe([['name' => 'keep one'], ['name' => 'keep two']]);
});

it('round-trips a relationship repeater\'s published rows unchanged', function () {
    // What the client actually does: seed from the payload, submit it back
    // untouched while editing another field. Delete-all-then-recreate means
    // the ids change; the CONTENT must not.
    $banner = seedBanner('Rows');
    $banner->tags()->createMany([['name' => 'keep one'], ['name' => 'keep two']]);

    $published = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->json('data.tag_rows');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Renamed only',
            'body_html' => '<p>Body</p>',
            'tag_rows' => $published,
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->pluck('tags.name')->all())
        ->toBe(['keep one', 'keep two']);
});

it('still clears a relationship repeater on an explicit empty list', function () {
    // The distinction the null guard must not blunt: `[]` is an answer.
    $banner = seedBanner('Rows');
    $banner->tags()->createMany([['name' => 'gone one'], ['name' => 'gone two']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Rows',
            'body_html' => '<p>Body</p>',
            'tag_rows' => [],
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->count())->toBe(0);
});

it('replaces a relationship repeater\'s rows wholesale — the wire carries no record keys', function () {
    // The honest edge of the P9 unblock, pinned rather than implied.
    // Repeater::saveToRelationship() matches submitted rows to existing
    // records by a `record-{id}` STATE key, which the repeater's wire shape
    // (a plain list of maps) cannot express — so every save deletes the
    // existing child ROWS and recreates them from the submission. For the
    // BelongsToMany fixture that means the Tag row itself is deleted, not
    // detached. On the web, Livewire state carries the record keys, so rows
    // update in place; a panel whose relationship repeater rows hold data
    // the item template does not edit loses that data on every mobile save.
    $banner = seedBanner('Replace');
    $old = Tag::query()->create(['name' => 'old row']);
    $banner->tags()->attach($old);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Replace',
            'body_html' => '<p>Body</p>',
            'tag_rows' => [['name' => 'old row, edited']],
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->pluck('tags.name')->all())->toBe(['old row, edited'])
        ->and(Tag::query()->find($old->id))->toBeNull();
});

it('creates a record with rows the same way update does', function () {
    // Assertion 6.
    $rows = [['sku' => 'A', 'qty' => 1], ['sku' => 'B', 'qty' => 2]];

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Created with items',
            'body_html' => '<p>Body</p>',
            'line_items' => $rows,
        ])
        ->assertCreated();

    $banner = Banner::query()->where('name', 'Created with items')->firstOrFail();

    expect(keySorted($banner->line_items))->toBe(keySorted($rows));
});

it('422s a create carrying fewer rows than minItems, the same way update does', function () {
    // Assertion 6, the negative half — the same rules apply on both endpoints.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Rejected create',
            'body_html' => '<p>Body</p>',
            'line_items' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['line_items']);

    expect(Banner::query()->where('name', 'Rejected create')->exists())->toBeFalse();
});

it('leaves a disabled repeater column untouched on create too', function () {
    // Assertion 6, alongside assertion 4: create() must refuse exactly like
    // update() does.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Locked on create',
            'body_html' => '<p>Body</p>',
            'locked_rows' => [['note' => 'sneaky']],
        ])
        ->assertCreated();

    $banner = Banner::query()->where('name', 'Locked on create')->firstOrFail();

    expect($banner->locked_rows)->toBeNull();
});

/**
 * P6c close-out, Finding 1, end to end and against the real column. This is
 * the assertion the whole refusal exists for: `guarded_rows` holds a
 * `Hidden::make('id')` in its item template, so before the fix a save that
 * mentioned the field at all rebuilt every row without its `id` — 200, no
 * warning, identifier gone. Now the field carries no rule, so its key never
 * reaches the validated payload and `update()` never touches the column.
 */
it('leaves a refused repeater\'s stored rows untouched when a crafted payload names it', function () {
    $stored = [['sku' => 'A', 'id' => 7]];
    $banner = seedBannerWith(['guarded_rows' => $stored]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'guarded_rows' => [['sku' => 'A']],
        ])
        ->assertOk();

    expect(keySorted($banner->fresh()->guarded_rows))->toBe(keySorted($stored));
});

/**
 * P6c close-out, Finding 3. PHP's `array` admits a string-keyed map, and the
 * per-item wildcard rules match a literal `*` key perfectly happily, so this
 * payload validated cleanly and was stored verbatim behind a 200. The client
 * then rendered zero rows and the first Add overwrote what was there.
 */
it('refuses a repeater value that is a keyed map rather than a list', function () {
    $banner = seedBannerWith(['line_items' => [['sku' => 'A', 'qty' => 1]]]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'line_items' => ['*' => ['sku' => 'B', 'qty' => 2]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['line_items']);

    expect(keySorted($banner->fresh()->line_items))->toBe(keySorted([['sku' => 'A', 'qty' => 1]]));
});

/**
 * P6c close-out re-review, the same refusal earned a different way and the
 * one shape the first pass missed. `RuleExtractor::withheldChild()` mirrored
 * `childrenOf()`'s refusals, but the rules come from `fromComponents()`,
 * which drops every relation-write leaf as well — so a
 * `CheckboxList::relationship()->dehydrated(true)` in an item template was
 * published editable with no rule naming it, and `validated()` deleted the
 * stored `tags` key from every row on every save. Measured before the fix:
 * `[{"title":"A","tags":[1,2]}]` came back `[{"title":"A-EDITED"}]` behind a
 * `200`. The column, not the status code, is the assertion — a 200 was never
 * the problem.
 */
it('leaves the stored rows of a repeater with a forced relation-write child untouched', function () {
    $stored = [['title' => 'A', 'tags' => [1, 2]]];
    $banner = seedBannerWith(['relation_rows' => $stored]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'relation_rows' => [['title' => 'A-EDITED', 'tags' => [1, 2]]],
        ])
        ->assertOk();

    expect(keySorted($banner->fresh()->relation_rows))->toBe(keySorted($stored));
});
