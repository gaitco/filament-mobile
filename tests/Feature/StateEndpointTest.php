<?php

declare(strict_types=1);

// Same reset SchemaEndpointTest carries, for the same reason: the production
// test flips the app environment and never flips it back, so Testbench's
// `migrate:rollback` teardown would hit ConfirmableTrait's production gate.
afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('returns the re-walked form for the submitted state', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null,
            'state' => ['name' => 'x'],
            'changed' => 'name',
        ]);

    $response->assertOk()->assertJsonStructure(['components']);
    expect($response->json('components'))->not->toBeEmpty();
});

it('re-evaluates visibility against the submitted values', function () {
    // The fixture form hides `city_id` unless `country_id` is 3.
    $hidden = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null,
            'state' => ['country_id' => 99],
            'changed' => 'country_id',
        ])->json('components');

    $shown = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null,
            'state' => ['country_id' => 3],
            'changed' => 'country_id',
        ])->json('components');

    $find = fn (array $nodes, string $name) => collect($nodes)
        ->firstWhere('name', $name);

    expect($find($hidden, 'city_id')['hidden'])->toBeTrue()
        ->and($find($shown, 'city_id')['hidden'])->toBeFalse();
});

it('authorizes create when record_id is null and update when it is not', function () {
    $post = seedPost();

    $this->actingAs(makeUser('restricted'))
        ->postJson('/api/mobile-panel/posts/state', [
            'record_id' => null, 'state' => [], 'changed' => null,
        ])->assertForbidden();

    $this->actingAs(makeUser('owned-denier'))
        ->postJson('/api/mobile-panel/posts/state', [
            'record_id' => $post->id, 'state' => [], 'changed' => null,
        ])->assertForbidden();
});

it('resolves a field from the record when the payload does not resend it', function () {
    // `active_note` is visible only when `status` is active, and the payload
    // never mentions status — the record has to supply it, or the form the
    // client renders disagrees with the rule set update() will accept.
    $find = fn (array $nodes, string $name) => collect($nodes)->firstWhere('name', $name);

    $active = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => seedBanner(status: 'active')->id,
            'state' => ['name' => 'x'],
            'changed' => 'name',
        ])->json('components');

    $draft = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => seedBanner(status: 'draft')->id,
            'state' => ['name' => 'x'],
            'changed' => 'name',
        ])->json('components');

    expect($find($active, 'active_note')['hidden'])->toBeFalse()
        ->and($find($draft, 'active_note')['hidden'])->toBeTrue();
});

it('lets the submitted value win over the stored one', function () {
    // The merge is one-directional: the record fills the gaps, it never
    // overrides what the user has just typed.
    $banner = seedBanner(status: 'active');

    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => $banner->id,
            'state' => ['status' => 'draft'],
            'changed' => 'status',
        ])->json('components');

    expect(collect($components)->firstWhere('name', 'active_note')['hidden'])->toBeTrue();
});

it('seeds the record with its CAST values, not raw column values', function () {
    // `options` is `array`-cast; `featured_note` is visible only when it reads
    // back as a real array. Seeded from getAttributes() the closure sees the
    // JSON string and hides the field, so this is the test that would go red on
    // a revert — the parity claim, not just the merge.
    $banner = seedBanner(options: ['featured' => true]);

    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => $banner->id, 'state' => [], 'changed' => null,
        ])->json('components');

    expect(collect($components)->firstWhere('name', 'featured_note')['hidden'])->toBeFalse();
});

it('404s for a resource that declares no mobile()', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/secrets/state', [
            'record_id' => null, 'state' => [], 'changed' => null,
        ])->assertNotFound();
});

it('degrades a throwing closure to a warning rather than a 500', function () {
    // The fixture form has a field whose visible() closure throws.
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null, 'state' => [], 'changed' => null,
        ])->assertOk();

    // A 200 alone is not the assertion: an implementation that dropped the
    // whole form on the first bad closure would also answer 200. The warning
    // naming the field is what proves one component degraded and the rest of
    // the form survived.
    // `company_id` is the throwing field's own sibling inside the section, so
    // this is the tightest available proof that degradation stopped at one
    // component rather than taking its container down with it.
    expect(collect($response->json('_warnings'))->pluck('component'))->toContain('trap')
        ->and(collect($response->json('components.0.children'))->pluck('name'))
        ->toContain('company_id');
});

it('omits _warnings in production so a phone never receives diagnostics', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null, 'state' => [], 'changed' => null,
        ])
        ->assertOk()
        ->assertJsonMissingPath('_warnings');
});

it('requires authentication', function () {
    $this->postJson('/api/mobile-panel/banners/state', [
        'record_id' => null, 'state' => [], 'changed' => null,
    ])->assertUnauthorized();
});

it('rejects a non-array state', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null, 'state' => 'nope', 'changed' => null,
        ])->assertStatus(422);
});

it('reports a conditional field hidden in /schema and visible in /state', function () {
    // This is the whole point of the phase's `hidden` ruling: /schema is an
    // empty-form snapshot and only a first-paint hint, while /state is
    // evaluated against real values and is authoritative.
    //
    // AMENDED IN TASK 6 (the original expected /schema to report `hidden:
    // false`). /schema now builds through an unseeded HeadlessSchemaHost and
    // reads getComponents(withHidden: true), so it genuinely evaluates the
    // closure against an empty form: `city_id` is reported hidden, and it takes
    // real submitted values in /state to reveal it. Both endpoints publish the
    // same component set, which is what makes the two `hidden` values
    // comparable at all — before Task 6, /schema dropped the field entirely.
    $user = makeUser('admin');

    $fromSchema = collect(
        $this->actingAs($user)->getJson('/api/mobile-panel/schema')
            ->json('resources'),
    )->firstWhere('key', 'banners');

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

    $fromState = $this->actingAs($user)
        ->postJson('/api/mobile-panel/banners/state', [
            'record_id' => null, 'state' => ['country_id' => 3], 'changed' => 'country_id',
        ])->json('components');

    expect($find($fromSchema['form'], 'city_id')['hidden'])->toBeTrue()
        ->and($find($fromState, 'city_id')['hidden'])->toBeFalse();
});
