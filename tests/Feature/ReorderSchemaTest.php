<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDescResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDisabledResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlidePivotResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideResource;

// Not the shared TestCase list — same reasoning as RelationSchemaTest's
// beforeEach: these fixtures exist for this slice only.
beforeEach(function () {
    config()->set('filament-mobile.resources', [
        SlideResource::class,
        SlideDescResource::class,
        SlidePivotResource::class,
        ArticleResource::class,
        SlideDisabledResource::class,
    ]);
});

it('publishes reorder for an authorized reorderable resource', function () {
    // schemaFor() acts as makeUser('admin') — SlideResource's
    // authorizeReorder() closure allows exactly that email.
    expect(schemaFor('slides')['reorder'])->toBe(['column' => 'position', 'direction' => 'asc']);
});

it('publishes a desc direction', function () {
    expect(schemaFor('slide-descs')['reorder'])->toBe(['column' => 'position', 'direction' => 'desc']);
});

it('omits reorder for a resource with no reorderable column', function () {
    expect(schemaFor('articles'))->not->toHaveKey('reorder');
});

it('omits reorder for a dotted pivot column — not offered on mobile', function () {
    expect(schemaFor('slide-pivots'))->not->toHaveKey('reorder');
});

it('omits reorder when authorizeReorder denies this user', function () {
    $resources = collect(
        test()->actingAs(makeUser('other'))
            ->getJson('/api/mobile-panel/schema')
            ->assertOk()
            ->json('resources'),
    );

    expect($resources->firstWhere('key', 'slides'))->not->toHaveKey('reorder');
});

it('omits reorder when reorderable()\'s own condition is false, even for an authorized user', function () {
    // SlideDisabledResource declares condition: false and no
    // authorizeReorder() override (default: always authorized) — proves
    // the condition is evaluated as its own gate, not folded into
    // authorizeReorder()'s absence.
    expect(schemaFor('slide-disableds'))->not->toHaveKey('reorder');
});

it('never publishes reorder as null — absent, not null, when unauthorized', function () {
    $body = test()->actingAs(makeUser('other'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('"reorder":null');
});
