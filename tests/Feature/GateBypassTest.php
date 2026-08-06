<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * Second round of hardening on the write endpoint.
 *
 * `UnsavedFieldTest` covers the gates that answer from `auth()` or from
 * `$state`. These are the ones that answer from the *schema's own context* —
 * which operation this is, and which record — and all of them were open
 * because nothing in this package ever called `Schema::operation()` or
 * `Schema::record()`.
 *
 * Each case is written twice on purpose: once proving the gate now closes, and
 * once proving the field is still writable when the gate genuinely says yes.
 * A guard that refuses everything would pass the first half alone.
 */

/** @return array<string, mixed> the payload plus the fields every write here needs, since `name` and `body_html` are required */
function crafted(array $fields): array
{
    return ['name' => 'Crafted', 'body_html' => '<p>Body</p>', ...$fields];
}

it('refuses a disabledOn(edit) field on update', function () {
    $banner = seedBanner('Immutable');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", crafted(['bypass_note' => 'crafted']))
        ->assertOk();

    expect($banner->fresh()->bypass_note)->toBeNull();
});

it('still writes a disabledOn(edit) field on create, where the gate says yes', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted(['bypass_note' => 'legitimate']))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('bypass_note'))->toBe('legitimate');
});

it('refuses a record-dependent gate on update, where the record now exists', function () {
    $banner = seedBanner('Locked');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", crafted(['rec_note' => 'crafted']))
        ->assertOk();

    expect($banner->fresh()->rec_note)->toBeNull();
});

it('still writes the record-dependent field on create, where there is no record', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted(['rec_note' => 'legitimate']))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('rec_note'))->toBe('legitimate');
});

/**
 * The stricter signature was the more exposed one: a non-nullable `Model
 * $record` hint TypeErrors when there is no record, and the guard read that as
 * "no opinion" and admitted the field.
 */
it('refuses a field whose gate throws rather than admitting it', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted(['strict_note' => 'crafted']))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('strict_note'))->toBeNull();
});

it('writes that same field on update, where its gate resolves and says yes', function () {
    $banner = seedBanner('Resolvable');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", crafted(['strict_note' => 'legitimate']))
        ->assertOk();

    expect($banner->fresh()->strict_note)->toBe('legitimate');
});

/**
 * The defaults path. A default is the other way a value reaches the row, so a
 * guard on the validated payload alone guards nothing.
 */
it('does not apply the default of a field hidden in this operation', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted([]))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('op_note'))->toBeNull();
});

it('does not apply a default from inside a disabled section', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted([]))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('gated_note'))->toBeNull();
});

it('does not apply the default of a hidden field', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted([]))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('hidden_note'))->toBeNull();
});

it('still applies an ungated default, so the guard has not eaten the feature', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', crafted([]))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Crafted')->value('kind'))->toBe('promo');
});

/**
 * /state publishes the flags a client renders from. If it answers as a create
 * while the write refuses the field, the form draws itself editable and then
 * silently drops what the user typed — worse than either answer alone.
 */
it('reports the operation gate as disabled on the edit path of /state', function () {
    $banner = seedBanner('Editing');

    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', ['record_id' => $banner->id, 'state' => []])
        ->assertOk()
        ->json('components.0.children');

    $field = collect($components)->firstWhere('name', 'bypass_note');

    expect($field['disabled'])->toBeTrue();
});

it('reports it as enabled on the create path of /state', function () {
    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', ['state' => []])
        ->assertOk()
        ->json('components.0.children');

    $field = collect($components)->firstWhere('name', 'bypass_note');

    expect($field['disabled'])->toBeFalse();
});

/**
 * Review round 3. The carve-out that keeps a *bare* component's structural
 * throw from refusing every field was matched on the exception's **message
 * text** — and a client can put text into that message.
 *
 * `disabled(fn (Get $get) => Model::findOrFail($get('kind'))->...)` is an
 * ordinary gate. ModelNotFoundException appends the ids it looked for, so a
 * crafted `kind` reproduced the magic string and readmitted the original bug on
 * a payload the attacker composes. The question is now asked of the component —
 * is it wired into a Schema — which is a fact about the object.
 */
it('refuses a gate whose exception message is client-supplied', function () {
    $banner = seedBanner('Steered');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", crafted([
            // `kind` is Hidden, so it is never written — but it does seed the
            // state the gate above reads, and this value lands verbatim in the
            // ModelNotFoundException the gate then raises.
            'kind' => 'nope, $container must not be accessed',
            'probe_note' => 'crafted',
        ]))
        ->assertOk();

    expect($banner->fresh()->probe_note)->toBeNull();
});

it('refuses the same gate for an ordinary missing id, so the control matches', function () {
    $banner = seedBanner('Ordinary');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", crafted([
            'kind' => '999999',
            'probe_note' => 'crafted',
        ]))
        ->assertOk();

    expect($banner->fresh()->probe_note)->toBeNull();
});

/**
 * The publish half of the same pair. A gate that throws refuses on the write,
 * so reporting the field as editable would have /state draw it and update()
 * silently drop what the user typed.
 */
it('reports a throwing disabled gate as locked in /state, matching the write', function () {
    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', ['state' => []])
        ->assertOk()
        ->json('components.0.children');

    expect(collect($components)->firstWhere('name', 'probe_note')['disabled'])->toBeTrue();
});

it('reports a throwing visible gate as hidden in /state, matching the write', function () {
    $components = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', ['state' => []])
        ->assertOk()
        ->json('components.0.children');

    expect(collect($components)->firstWhere('name', 'trap')['hidden'])->toBeTrue();
});

it('names the locked field in the warnings rather than failing closed in silence', function () {
    $warnings = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', ['state' => []])
        ->assertOk()
        ->json('_warnings');

    expect(collect($warnings)->pluck('reason')->filter(
        fn (string $r): bool => str_contains($r, 'locking the field'),
    ))->not->toBeEmpty();
});
