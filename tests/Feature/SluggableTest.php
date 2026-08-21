<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Page;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PageResource;

/**
 * P16: pins the three documented `spatie/laravel-sluggable` semantics
 * through the real write endpoints, so a vendor upgrade cannot silently
 * drift the claims in the "Sluggable" README section without failing a
 * test. See docs/superpowers/specs/2026-08-21-p16-sluggable-design.md — no
 * walker/serializer/write-path change backs this; the field is a plain
 * `TextInput`, and this package does nothing special with it.
 */
beforeEach(function (): void {
    config()->set('filament-mobile.resources', [
        PageResource::class,
    ]);
});

it('keeps a custom non-empty slug submitted on create', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/pages', [
            'title' => 'My Page',
            'slug' => 'a-totally-unique-custom-slug',
        ])
        ->assertCreated();

    expect(Page::query()->where('title', 'My Page')->sole()->slug)
        ->toBe('a-totally-unique-custom-slug');
});

it('generates a slug from the title when slug is empty on create', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/pages', [
            'title' => 'Generated From Title',
            'slug' => '',
        ])
        ->assertCreated();

    expect(Page::query()->where('title', 'Generated From Title')->sole()->slug)
        ->toBe('generated-from-title');
});

it('generates a slug from the title when slug is absent on create', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/pages', [
            'title' => 'Absent Slug Title',
        ])
        ->assertCreated();

    expect(Page::query()->where('title', 'Absent Slug Title')->sole()->slug)
        ->toBe('absent-slug-title');
});

/**
 * The subtle case: the client resubmits the whole record state, including
 * the STORED slug, unchanged — the same request shape a mobile edit screen
 * sends. Spatie's own `hasCustomSlugBeenUsed()` check sees no change to the
 * slug field itself, so it does not treat this as a custom value and
 * regenerates from the new title instead of keeping the stale one.
 */
it('regenerates the slug from a new title when the stored slug is resubmitted unchanged', function () {
    $page = Page::create(['title' => 'Original Title', 'slug' => 'original-title']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/pages/{$page->id}", [
            'title' => 'Updated Title',
            'slug' => 'original-title',
        ])
        ->assertOk();

    expect($page->fresh()->slug)->toBe('updated-title');
});

it('keeps a changed slug submitted on update', function () {
    $page = Page::create(['title' => 'Original Title', 'slug' => 'original-title']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/pages/{$page->id}", [
            'title' => 'Original Title',
            'slug' => 'deliberately-changed-slug',
        ])
        ->assertOk();

    expect($page->fresh()->slug)->toBe('deliberately-changed-slug');
});
