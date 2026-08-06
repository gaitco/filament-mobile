<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

it('creates a record and returns it', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', ['name' => 'لافتة جديدة', 'body_html' => '<p>Body</p>']);

    $response->assertCreated()->assertJsonPath('data.name', 'لافتة جديدة');
    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
        ->where('name', 'لافتة جديدة')->exists())->toBeTrue();
});

it('422s with errors keyed by field name', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

it('403s when the policy denies create', function () {
    $this->actingAs(makeUser('restricted'))
        ->postJson('/api/mobile-panel/posts', ['title' => 'x'])
        ->assertForbidden();
});

it('404s for a resource that declares no mobile()', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/secrets', [])
        ->assertNotFound();
});

it('requires authentication', function () {
    $this->postJson('/api/mobile-panel/banners', [])->assertUnauthorized();
});

it('404s before authorizing, so a missing resource never leaks a 403', function () {
    $this->actingAs(makeUser('restricted'))
        ->postJson('/api/mobile-panel/nope', [])
        ->assertNotFound();
});

it('ignores a key the form does not declare', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'اسم',
            'body_html' => '<p>Body</p>',
            'internal_note' => 'should not be written',
        ])
        ->assertCreated();

    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
        ->latest('id')->first()->internal_note)->toBeNull();
});

it('updates a record', function () {
    $banner = seedBanner(name: 'قديم');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", ['name' => 'جديد', 'body_html' => '<p>Body</p>'])
        ->assertOk()
        ->assertJsonPath('data.name', 'جديد');

    expect($banner->fresh()->name)->toBe('جديد');
});

it('403s when the RECORD policy denies update even though the resource permits it', function () {
    // The resource-level block means capability; the record block means
    // authorization. Reusing the class-level path here would permit every record.
    $post = seedPost();

    $this->actingAs(makeUser('owned-denier'))
        ->putJson("/api/mobile-panel/posts/{$post->id}", ['title' => 'x'])
        ->assertForbidden();
});

it('extracts rules from the record CAST values, not raw column values', function () {
    // `featured_note` is visible only when the `array`-cast `options` reads
    // back as an array. Hidden fields yield no rule, and only ruled keys
    // survive validate(), so seeding the form from getAttributes() — where
    // `options` is still a JSON string — silently drops the submitted value.
    $banner = seedBanner(options: ['featured' => true]);

    $this->actingAs(makeUser('admin'))
        // `name` rides along because the form declares it required and this
        // endpoint validates the whole rule set, not just the sent keys.
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'featured_note' => 'kept',
        ])
        ->assertOk();

    expect($banner->fresh()->featured_note)->toBe('kept');
});

it('404s for a record outside the resource query scope', function () {
    $banner = seedBanner();
    $banner->update(['deleted_at' => now()]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", ['name' => 'x'])
        ->assertNotFound();
});

it('keeps the sibling keys of a nested attribute an update does not resend', function () {
    // The pilot's "translatable edit writes blanks back", reached from the
    // update side. `caption.en` validates into ['caption' => ['en' => ...]] and
    // Eloquent writes the WHOLE `caption` column, so `caption.ar` — Hidden, and
    // therefore absent from every payload by construction — was erased on every
    // save. The same holds for any locale that is disabled, disabledOn('edit')
    // or dehydration-refused.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Translatable',
            'body_html' => '<p>Body</p>',
            'caption' => ['en' => 'EN'],
        ])
        ->assertCreated();

    $banner = Banner::query()->where('name', 'Translatable')->firstOrFail();

    // toEqual, not toBe: the payload is written first and the missing paths
    // fill in behind it, so the stored key order is payload-first.
    expect($banner->caption)->toEqual(['ar' => 'AR default', 'en' => 'EN']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Translatable',
            'body_html' => '<p>Body</p>',
            'caption' => ['en' => 'EN2'],
        ])
        ->assertOk();

    expect($banner->fresh()->caption)->toEqual(['ar' => 'AR default', 'en' => 'EN2']);
});

it('never grafts a stored list onto the submitted value by index', function () {
    // The stored fill is subject to the same rule as the defaults: a list is
    // one indivisible value, never a set of index paths. A row whose `caption`
    // holds a list — a legacy shape, or any JSON column the form addresses by
    // path — must be replaced by what was submitted, not have ['legacy'] merged
    // into it as `caption.0`.
    $banner = seedBanner();
    $banner->update(['caption' => ['legacy']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'caption' => ['en' => 'EN'],
        ])
        ->assertOk();

    expect($banner->fresh()->caption)->toBe(['en' => 'EN']);
});

it('422s with errors keyed by field name on update', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", ['name' => ''])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});
