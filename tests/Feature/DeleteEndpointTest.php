<?php

declare(strict_types=1);

it('deletes a record the policy permits', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertNoContent();

    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
        ->whereKey($banner->id)->exists())->toBeFalse();
});

it('403s when the RECORD policy denies delete, leaving the record intact', function () {
    $post = seedPost();

    $this->actingAs(makeUser('owned-denier'))
        ->deleteJson("/api/mobile-panel/posts/{$post->id}")
        ->assertForbidden();

    expect(Gait\FilamentMobile\Tests\Fixtures\Models\Post::query()
        ->whereKey($post->id)->exists())->toBeTrue();
});

it('404s for a record outside the resource query scope', function () {
    $banner = seedBanner();
    $banner->update(['deleted_at' => now()]);

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertNotFound();
});

it('404s for a resource that declares no mobile()', function () {
    $this->actingAs(makeUser('admin'))
        ->deleteJson('/api/mobile-panel/secrets/1')
        ->assertNotFound();
});

it('requires authentication', function () {
    $banner = seedBanner();

    $this->deleteJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertUnauthorized();
});
