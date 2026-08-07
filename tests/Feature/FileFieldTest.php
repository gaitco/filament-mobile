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
