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

it('ignores a file key a client sends anyway', function () {
    // Upload is P6. A client that submits one must not be able to overwrite or
    // clear the stored value by accident or on purpose.
    $banner = seedBanner();
    $banner->update(['avatar' => 'original.jpg']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'جديد',
            'body_html' => '<p>Body</p>',
            'avatar' => null,
        ])
        ->assertOk();

    expect($banner->fresh()->avatar)->toBe('original.jpg');
});

it('ignores a file key on create', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'اسم',
            'body_html' => '<p>Body</p>',
            'avatar' => 'attacker.jpg',
        ])
        ->assertCreated();

    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
        ->latest('id')->first()->avatar)->toBeNull();
});

it('still publishes the file field to the client, read-only', function () {
    // The value is never written, but the field is not dropped either: a form
    // that silently lost its image field would be the `icon_entry` mistake
    // again — a component one side knows about and the other never sees.
    $resources = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->json('resources');

    $banners = collect($resources)->firstWhere('key', 'banners');
    $avatar = collect($banners['form'])->firstWhere('name', 'avatar');

    expect($avatar['type'])->toBe('file')
        ->and($avatar['config']['readOnly'])->toBeTrue();
});
