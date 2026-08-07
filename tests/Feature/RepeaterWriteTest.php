<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * P6c Task 3: the write path round-trips a repeater's rows through the
 * UNMODIFIED store()/update() — the value is an ordinary attribute
 * (`line_items` is `array`-cast) and Laravel's validated payload carries the
 * whole array for `line_items.*` rules (verified empirically before the plan
 * was written — see the design spec). No production code changed to make
 * any test in this file pass; a failure here would be a genuine finding
 * about the write path, not licence to special-case store()/update().
 *
 * Assertions read the record directly (`->fresh()->line_items`), the same
 * convention WriteEndpointTest already uses for every column check —
 * deliberately not through the show() endpoint's `data` payload, which
 * store()/update() never populate with form fields (no withFormPaths()
 * call) and which has its own, unrelated, pre-existing gap for a repeater's
 * leaf name (MobilePanelController::leafNames() treats every node carrying
 * `children` — repeater included — as a pass-through container, so a
 * repeater's OWN name never reaches formPaths(); read path, not this task).
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

    expect($banner->fresh()->line_items)->toBe($rows);
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

it('drops a relationship repeater\'s submitted rows, attaching nothing', function () {
    // Assertion 5. `tag_rows` — Repeater::relationship('tags'); no column
    // of its own, so the only observable is the pivot staying empty.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'tag_rows' => [['name' => 'sneaky']],
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->count())->toBe(0);
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

    expect($banner->line_items)->toBe($rows);
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

    expect($banner->fresh()->guarded_rows)->toBe($stored);
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

    expect($banner->fresh()->line_items)->toBe([['sku' => 'A', 'qty' => 1]]);
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

    expect($banner->fresh()->relation_rows)->toBe($stored);
});
