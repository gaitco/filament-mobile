<?php

declare(strict_types=1);

it('publishes the available actions on the record payload', function () {
    $banner = seedBanner('Payload');

    $actions = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('actions');

    expect(array_column($actions, 'name'))
        ->toBe(['approve', 'archive', 'explode', 'halting', 'throwing_label', 'throwing_confirmation', 'failing', 'cancelling', 'html_label'])
        ->and($actions[0])->toHaveKeys(['name', 'label', 'color', 'icon', 'confirmation']);
});

it('answers 200, not 500, for a record whose resource carries an action with a throwing presentation closure', function () {
    // The blast-radius test: before ActionResolver::serialise() guarded
    // its getters, a throwing label/color/icon/modal closure on ANY
    // opted-in action 500'd the whole record payload — data and
    // permissions included — not just that one action.
    $banner = seedBanner('Blast');

    $actions = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson("/api/mobile-panel/banners/{$banner->id}")
            ->assertOk()
            ->json('actions'),
    )->keyBy('name');

    expect($actions['throwing_label']['label'])->toBe('throwing_label')
        ->and($actions['throwing_confirmation']['confirmation'])->not->toBeNull()
        ->and($actions['throwing_confirmation']['confirmation']['submit'])->toBe('');
});

it('degrades an Htmlable label and modal heading rather than shipping blanks', function () {
    // text() answers null for Htmlable, and `(string) null` is `''` — which
    // is still a String on the wire, so the Dart parser's fallback never
    // fires and the screen renders a blank tappable button. The machine name
    // is the floor for the label, the generic prompt for the heading.
    $banner = seedBanner('Html');

    $actions = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson("/api/mobile-panel/banners/{$banner->id}")
            ->assertOk()
            ->json('actions'),
    )->keyBy('name');

    expect($actions['html_label']['label'])->toBe('html_label')
        ->and($actions['html_label']['confirmation']['heading'])->toBe('Are you sure?');
});

it('omits an action hidden for this record', function () {
    // `publish` is visible only for a draft — the same payload for a
    // non-draft record must not carry it.
    $active = seedBanner('Active');
    $draft = seedBannerWith(['name' => 'Draft', 'status' => 'draft']);
    $user = makeUser('admin');

    $names = fn ($id) => array_column(
        $this->actingAs($user)->getJson("/api/mobile-panel/banners/{$id}")->json('actions'),
        'name',
    );

    expect($names($active->id))->not->toContain('publish')
        ->and($names($draft->id))->toContain('publish');
});

it('carries the confirmation block only for an action that requires it', function () {
    $banner = seedBanner('Confirm');

    $actions = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson("/api/mobile-panel/banners/{$banner->id}")
            ->json('actions'),
    )->keyBy('name');

    expect($actions['approve']['confirmation'])->toBeNull()
        ->and($actions['archive']['confirmation']['heading'])->toBe('Archive this banner?');
});

it('answers an empty list for a resource that opted no actions in', function () {
    // Absence of the feature is an empty array, never a missing key: a
    // client that always reads `actions` must not have to guard for null.
    $post = seedPost('No actions');

    expect(
        $this->actingAs(makeUser('admin'))
            ->getJson("/api/mobile-panel/posts/{$post->id}")
            ->assertOk()
            ->json('actions'),
    )->toBe([]);
});
