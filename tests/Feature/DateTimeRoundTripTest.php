<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Post;

/**
 * P13 edge sweep, the two pins that need the whole HTTP stack rather than
 * the walker alone: a VALUE making the POST → GET trip without this package
 * converting a byte of it. The fixture columns are `string` (see the posts
 * migration) so the database itself is not part of what is being pinned — a
 * native DATETIME column would coerce a timezone-offset value on MySQL
 * before our code ever saw it again.
 *
 * The write half is a POST, not a PUT, because PostPolicy::update() denies
 * everyone by design (it pins the capability semantics of the permissions
 * block) — create is the one write seam posts legitimately have, and it
 * runs the same validate-then-save path update() does.
 *
 * `closes_at` is the field left at the vendor seconds default of `true`,
 * which is what makes the first test a seconds-precision value at all;
 * `published_at` carries real declared bounds (2026-01-01 … 2026-12-31),
 * and the offset value sits inside them.
 */
it('round-trips a seconds-precision time value byte-for-byte', function () {
    // `HH:mm:ss`, the shape a `seconds: true` time node carries on the wire.
    $user = makeUser('admin');

    test()->actingAs($user)
        ->postJson('/api/mobile-panel/posts', [
            'title' => 'Post',
            'closes_at' => '09:15:30',
        ])
        ->assertCreated();

    $post = Post::latest('id')->firstOrFail();

    // Stored verbatim first, so a read-side accident cannot hide a
    // write-side conversion.
    expect($post->getAttribute('closes_at'))->toBe('09:15:30');

    test()->actingAs($user)
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.closes_at', '09:15:30');
});

it('round-trips a timezone-offset datetime value byte-for-byte', function () {
    // The color-field rule applied to dates: no conversion, anywhere. The
    // value carries its `+02:00` offset through storage and the read
    // untouched — a server that "helpfully" normalised to UTC would turn
    // this into `2026-06-15 07:00:00` and fail the pin.
    $value = '2026-06-15T09:00:00+02:00';
    $user = makeUser('admin');

    test()->actingAs($user)
        ->postJson('/api/mobile-panel/posts', [
            'title' => 'Post',
            'published_at' => $value,
        ])
        ->assertCreated();

    $post = Post::latest('id')->firstOrFail();

    expect($post->getAttribute('published_at'))->toBe($value);

    test()->actingAs($user)
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.published_at', $value);
});
