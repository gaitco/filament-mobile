<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * The gate flip: a schema built from client-controlled state can have any gate
 * opened by crafting the value of a sibling the schema will never write.
 *
 * The steering field here is `kind`, a `Hidden`. That choice is the whole
 * point — see spec §2.0. A gate steered by a WRITABLE sibling is not an
 * escalation: a crafted `PUT` setting both fields dehydrates to exactly what a
 * user gets by changing one field in the panel, waiting for the reactive
 * round-trip, and typing into the other. Refusing there would remove a
 * capability the panel itself grants.
 *
 * Written twice, like GateBypassTest: two attacks and two guard rails. A
 * fixpoint that simply refused everything would pass the attacks alone, so the
 * guard rails are what make them mean anything.
 */
it('refuses a field whose gate a crafted sibling flipped, on update', function () {
    // PUT {"kind":"unlock","gate_note":"crafted"} — the gate reads `kind`, so
    // the submitted "unlock" makes it answer "not disabled" and the field would
    // be written. `kind` is Hidden: it never reaches the database, so the
    // stored value (null) is what the gate must see.
    $banner = seedBannerWith(['gate_note' => 'original']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Kept',
            'body_html' => '<p>Body</p>',
            'kind' => 'unlock',          // Hidden: never written, so never trusted
            'gate_note' => 'crafted',
        ])
        ->assertOk();

    expect($banner->fresh()->gate_note)->toBe('original');
});

it('refuses a field steered by a Hidden that never persists, on create', function () {
    // POST {"kind":"unlock","sib_note":"crafted"} — `kind` is never written,
    // but on create the host is seeded from the whole request, so it steers
    // `sib_note`'s gate. Pure escalation.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'R2',
            'body_html' => '<p>Body</p>',
            'kind' => 'unlock',
            'sib_note' => 'crafted',
        ])
        ->assertCreated();

    expect(Banner::latest('id')->first()->sib_note)->toBeNull();
});

it('still writes a field whose gate legitimately opens', function () {
    // The fix refuses MORE than before, so this is the guard rail: a gate
    // evaluated against TRUSTED state that says "writable" must still write.
    // Without this, a fixpoint that refused everything would pass both tests
    // above.
    // `kind`'s stored value opens the gate, so the field is genuinely
    // writable and the crafted-payload defence must not touch it.
    $banner = seedBannerWith(['kind' => 'unlock', 'gate_note' => 'original']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Kept',
            'body_html' => '<p>Body</p>',
            'gate_note' => 'legitimately updated',
        ])
        ->assertOk();

    expect($banner->fresh()->gate_note)->toBe('legitimately updated');
});

/**
 * The trusted state itself, on create. `trusted` is not only a reset target:
 * reset() seeds every pass's state from it and never re-derives it, so a value
 * that lands there is a permanent floor the final build reads — and the row's
 * defaults come off that build.
 *
 * One hop cannot show it, which is why it looked safe: the first component is
 * hidden by the settled state, so its own default never reaches the row. Two
 * hops do.
 */
it('refuses a default planted in the trusted state by a crafted gate, on create', function () {
    // `kind` opens `lever`; `lever`'s default 'lev' opens `victim_note`. Both
    // are Hidden, so neither is writable and neither value comes from the
    // client — but with `trusted` derived from the payload, 'LEAKED' lands in
    // a column no client could name and no panel would ever have written.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'TwoHop',
            'body_html' => '<p>Body</p>',
            'kind' => 'unlock',
        ])
        ->assertCreated();

    $banner = Banner::latest('id')->first();

    expect($banner->victim_note)->toBeNull()
        ->and($banner->lever)->toBeNull();
});

it('leaves an ordinary field untouched by the fixpoint', function () {
    $banner = seedBannerWith(['name' => 'Before']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", ['name' => 'After', 'body_html' => '<p>Body</p>'])
        ->assertOk();

    expect($banner->fresh()->name)->toBe('After');
});

/**
 * The steering field is `kind`, not `status`, and that is the whole test.
 * `status` is an ordinary writable Select: a client is entitled to set it, the
 * settle keeps it, and a gate it steers is not an escalation (spec §2.0). Only
 * a value the schema will NEVER write — `kind` is a `Hidden` — can flip a gate
 * the write path then refuses, which is the divergence these two endpoints must
 * not have.
 */
it('reports the same writable set that update() accepts, for a crafted payload', function () {
    // The property: whatever /state draws as editable is what the write will
    // take. A test that only checked `disabled` on one endpoint would pass
    // while the two disagreed.
    $banner = seedBannerWith(['status' => 'approved', 'gate_note' => 'original']);
    $user = makeUser('admin');

    $crafted = ['name' => 'Kept', 'body_html' => '<p>Body</p>', 'kind' => 'unlock', 'gate_note' => 'crafted'];

    $components = $this->actingAs($user)
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => $banner->id,
            'state' => $crafted,
            'changed' => 'kind',
        ])
        ->assertOk()
        ->json('components');

    $find = function (array $nodes, string $name) use (&$find) {
        foreach ($nodes as $node) {
            if (($node['name'] ?? null) === $name) {
                return $node;
            }
            $child = $find($node['children'] ?? [], $name);
            if ($child !== null) {
                return $child;
            }
        }

        return null;
    };

    // /state says: not writable.
    expect($find($components, 'gate_note')['disabled'])->toBeTrue();

    // And the write agrees.
    $this->actingAs($user)
        ->putJson("/api/mobile-panel/banners/{$banner->id}", $crafted)
        ->assertOk();

    expect($banner->fresh()->gate_note)->toBe('original');
});

it('reports a gate as open when trusted state opens it', function () {
    // The guard rail: a settle that simply locked everything would pass the
    // test above. The record's own `kind` opens the gate, so /state must draw
    // the field editable — and the write does accept it (third test up).
    $banner = seedBannerWith(['kind' => 'unlock', 'status' => 'draft', 'gate_note' => 'original']);

    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => $banner->id,
            'state' => ['status' => 'draft'],
            'changed' => 'status',
        ])
        ->assertOk()
        ->json('components');

    $find = function (array $nodes, string $name) use (&$find) {
        foreach ($nodes as $node) {
            if (($node['name'] ?? null) === $name) {
                return $node;
            }
            $child = $find($node['children'] ?? [], $name);
            if ($child !== null) {
                return $child;
            }
        }

        return null;
    };

    expect($find($components, 'gate_note')['disabled'])->toBeFalse();
});

it('reports a field the shrink-only allow-set refused, not what the last build says', function () {
    // The residual MobilePanelController::allowedRules() documents, seen from
    // /state. Stored `kind` opens the gate; the payload closes it with a value
    // that is never written, so pass 1 drops `gate_note` and the reset then
    // restores `kind` — the FINAL build reports the field writable again about
    // a value the client never sent. The write refuses it (shrink-only), so
    // /state must draw it locked. Reading `disabled` off the last build alone
    // reopens the divergence from the other side.
    $banner = seedBannerWith(['kind' => 'unlock', 'gate_note' => 'original']);
    $user = makeUser('admin');

    $crafted = ['name' => 'Kept', 'body_html' => '<p>Body</p>', 'kind' => 'promo', 'gate_note' => 'crafted'];

    $components = $this->actingAs($user)
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => $banner->id,
            'state' => $crafted,
            'changed' => 'kind',
        ])
        ->assertOk()
        ->json('components');

    $find = function (array $nodes, string $name) use (&$find) {
        foreach ($nodes as $node) {
            if (($node['name'] ?? null) === $name) {
                return $node;
            }
            $child = $find($node['children'] ?? [], $name);
            if ($child !== null) {
                return $child;
            }
        }

        return null;
    };

    expect($find($components, 'gate_note')['disabled'])->toBeTrue();

    $this->actingAs($user)
        ->putJson("/api/mobile-panel/banners/{$banner->id}", $crafted)
        ->assertOk();

    expect($banner->fresh()->gate_note)->toBe('original');
});
