<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\NoticeResource;

/**
 * P17 final review, Finding 1: StateController::walk() called SchemaWalker
 * without the model, unlike /schema (MobilePanelController::infolistNodes()/
 * formProjection()) — so `translatable` (and tags/rich/media) never reached
 * `/state`, only `/schema`. This pins /state parity for `translatable`.
 */
it('annotates translatable on a /state response, matching /schema', function () {
    config()->set('filament-mobile.resources', [NoticeResource::class]);

    $components = test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/notices/state', [
            'record_id' => null, 'state' => [], 'changed' => null,
        ])
        ->assertOk()
        ->json('components');

    expect(findNodeWhere($components, fn (array $n): bool => ($n['name'] ?? null) === 'caption.ar')['translatable'])->toBeTrue()
        ->and(findNodeWhere($components, fn (array $n): bool => ($n['name'] ?? null) === 'caption.en')['translatable'])->toBeTrue();
});
