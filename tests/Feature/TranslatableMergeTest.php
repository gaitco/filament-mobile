<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Notice;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NoticeResource;

/**
 * P17 Task 1: the endpoint-level half of the `storedPaths()` fix — see
 * `RecordFormStoredPathsTranslatableTest` for the unit-level pin and
 * `Notice`'s docblock for why this needs the REAL `HasTranslations` trait,
 * not the `TranslatableSerializationTest` fake.
 *
 * `getTranslations('caption')` is asserted directly throughout, never the
 * `caption` accessor — the accessor only ever answers for the app's current
 * locale, so asserting through it would prove nothing about the OTHER
 * locale the merge is supposed to have preserved.
 */
beforeEach(function (): void {
    config()->set('filament-mobile.resources', [
        NoticeResource::class,
    ]);
});

it('preserves the stored locale a partial update does not mention', function () {
    $notice = Notice::create([
        'title' => 'Weather',
        'caption' => ['ar' => 'مرحبا', 'en' => 'Hello'],
    ]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/notices/{$notice->id}", [
            'title' => 'Weather',
            'caption' => ['en' => 'Hello again'],
        ])
        ->assertOk();

    expect($notice->fresh()->getTranslations('caption'))
        ->toBe(['ar' => 'مرحبا', 'en' => 'Hello again']);
});

it('refills stored locales when the payload crafts an empty caption map', function () {
    $notice = Notice::create([
        'title' => 'Weather',
        'caption' => ['ar' => 'مرحبا', 'en' => 'Hello'],
    ]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/notices/{$notice->id}", [
            'title' => 'Weather',
            'caption' => [],
        ])
        ->assertOk();

    expect($notice->fresh()->getTranslations('caption'))
        ->toBe(['ar' => 'مرحبا', 'en' => 'Hello']);
});

it('round-trips both locales on a full update', function () {
    $notice = Notice::create([
        'title' => 'Weather',
        'caption' => ['ar' => 'مرحبا', 'en' => 'Hello'],
    ]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/notices/{$notice->id}", [
            'title' => 'Weather',
            'caption' => ['ar' => 'مرحبا بكم', 'en' => 'Welcome'],
        ])
        ->assertOk();

    expect($notice->fresh()->getTranslations('caption'))
        ->toBe(['ar' => 'مرحبا بكم', 'en' => 'Welcome']);
});
