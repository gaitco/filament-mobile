<?php

declare(strict_types=1);

it('leaves an existing file untouched when the update omits it', function () {
    $banner = seedBanner();
    $banner->update(['avatar' => 'original.jpg']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", ['name' => 'جديد', 'body_html' => '<p>Body</p>'])
        ->assertOk();

    expect($banner->fresh()->avatar)->toBe('original.jpg');
});

it('writes a file key a client sends, now that a single-file field carries a rule', function () {
    // P6a: `avatar` is single-file, so RuleExtractor now emits a rule for it
    // and it is an ordinary writable string column like any other leaf — the
    // path the upload endpoint hands back saves through this unmodified
    // write path (see Upload\UploadFieldResolver). An explicit null is the
    // same "explicit answer" every other nullable field honours: see
    // MobilePanelController::fillMissingPaths().
    $banner = seedBanner();
    $banner->update(['avatar' => 'original.jpg']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'avatar' => null,
        ])
        ->assertOk();

    expect($banner->fresh()->avatar)->toBeNull();
});

it('writes a file key on create, now that a single-file field carries a rule', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'اسم',
            'body_html' => '<p>Body</p>',
            'avatar' => 'some/path.jpg',
        ])
        ->assertCreated();

    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
        ->latest('id')->first()->avatar)->toBe('some/path.jpg');
});

it('publishes the file field to the client as writable, now that its value is written', function () {
    // P6a: `avatar` is single-file, so /schema stopped lying about it in
    // step with RuleExtractor (Task 1) and the write tests above — a form
    // that silently lost its image field would be the `icon_entry` mistake
    // again, and a field /schema calls read-only while the write path
    // genuinely saves it is the same mistake with the sign flipped.
    $resources = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->json('resources');

    $banners = collect($resources)->firstWhere('key', 'banners');
    $avatar = collect($banners['form'])->firstWhere('name', 'avatar');

    expect($avatar['type'])->toBe('file')
        ->and($avatar['config']['readOnly'])->toBeFalse();
});

it('saves a list of uploaded paths for a multiple field on create', function () {
    // P12: a multiple field's wire value is a List<String> of the paths the
    // upload endpoint handed back (one call per file), saved through the
    // ordinary, unmodified write path — the array cast stores it as JSON.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'اسم',
            'body_html' => '<p>Body</p>',
            'gallery' => ['uploads/a.png', 'uploads/b.png'],
        ])
        ->assertCreated();

    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
        ->latest('id')->first()->gallery)->toBe(['uploads/a.png', 'uploads/b.png']);
});

it('replaces a multiple field wholesale on update', function () {
    // The submitted list IS the whole new set — same model as the
    // relationship repeater: no per-element merge, no removal delta.
    $banner = seedBannerWith(['gallery' => ['uploads/old.png', 'uploads/older.png']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'gallery' => ['uploads/new.png'],
        ])
        ->assertOk();

    expect($banner->fresh()->gallery)->toBe(['uploads/new.png']);
});

it('clears a multiple field on a submitted empty list when no min bound forbids it', function () {
    $banner = seedBannerWith(['gallery' => ['uploads/a.png']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'gallery' => [],
        ])
        ->assertOk();

    expect($banner->fresh()->gallery)->toBe([]);
});

it('leaves a multiple field untouched when the update omits it', function () {
    // Dirty tracking: an unsubmitted field is not in the payload at all, so
    // wholesale replacement must never read an absent key as an empty list.
    $banner = seedBannerWith(['gallery' => ['uploads/a.png']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
        ])
        ->assertOk();

    expect($banner->fresh()->gallery)->toBe(['uploads/a.png']);
});

it('422s a multiple field submitted over its declared max files', function () {
    // The count bound is the server's, not a client hint: maxFiles(3) lands
    // as `max:3` on the ARRAY (Laravel counts elements), so the write —
    // not the upload loop — is where over-count is refused.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'attachments' => ['a.png', 'b.png', 'c.png', 'd.png'],
        ])
        ->assertJsonValidationErrors(['attachments']);

    expect($banner->fresh()->attachments)->toBeNull();
});

it('422s a multiple field submitted under its declared min files', function () {
    $banner = seedBannerWith(['attachments' => ['a.png']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'attachments' => [],
        ])
        ->assertJsonValidationErrors(['attachments']);

    expect($banner->fresh()->attachments)->toBe(['a.png']);
});

it('422s a non-string element of a multiple field, keyed per element', function () {
    // The tags precedent: `gallery.* => string` is what makes a crafted
    // `[1, 2]` a 422 keyed `gallery.0` rather than a stored list of ints
    // behind a 200.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'gallery' => [1, 2],
        ])
        ->assertJsonValidationErrors(['gallery.0', 'gallery.1']);

    expect($banner->fresh()->gallery)->toBeNull();
});

it('422s a scalar submitted for a multiple field rather than coercing it', function () {
    // The value is a List<String> in every case — a scalar is a contract
    // violation, not an invitation to wrap.
    $banner = seedBannerWith(['gallery' => ['uploads/a.png']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'gallery' => 'uploads/one.png',
        ])
        ->assertJsonValidationErrors(['gallery']);

    expect($banner->fresh()->gallery)->toBe(['uploads/a.png']);
});
