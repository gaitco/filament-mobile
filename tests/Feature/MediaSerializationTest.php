<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GalleryResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SecretResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Per-test, not the shared TestCase list — GalleryResource's own docblock and
// MediaFixtureTest's precedent: adding `galleries` to the default list would
// change ContractSnapshotTest's golden, whose job is "server without media".
// The default list's other three resources are carried over so `/banners/1`
// (used below for the medialess-model assertion) still resolves.
beforeEach(function (): void {
    config()->set('filament-mobile.resources', [
        PostResource::class,
        BannerResource::class,
        SecretResource::class,
        GalleryResource::class,
    ]);

    Storage::fake('public');
    $this->gallery = Gallery::create(['name' => 'Trip']);
    $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');
    $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('b.jpg')->toMediaCollection('photos');
    $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('c.jpg')->toMediaCollection('cover');
});

it('publishes uuids and a __media sibling on the record endpoint', function () {
    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/galleries/{$this->gallery->id}")
        ->assertOk()
        ->json('data');

    $uuids = $this->gallery->getMedia('photos')->pluck('uuid')->all();

    expect($data['photos'])->toBe($uuids)
        ->and($data['photos.__media'])->toHaveCount(2)
        ->and($data['photos.__media'][0])->toHaveKeys(['uuid', 'url', 'thumbUrl', 'name', 'size', 'mime'])
        ->and($data['photos.__media'][0]['uuid'])->toBe($uuids[0])
        ->and($data['photos.__media'][0]['name'])->toBe('a.jpg')
        ->and($data['cover'])->toBeString()   // single-file: one uuid, not a list
        ->and($data['cover.__media'])->toHaveCount(1);
});

it('publishes an empty collection as [] and absence for a mediless model', function () {
    $empty = Gallery::create(['name' => 'Empty']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/galleries/{$empty->id}")
        ->json('data');

    expect($data['photos'])->toBe([])
        ->and($data['photos.__media'])->toBe([])
        ->and($data['cover'])->toBeNull()
        ->and($data['cover.__media'])->toBe([]);

    // A resource whose model lacks HasMedia never grows the sibling: any
    // existing non-media record payload proves it.
    $banner = seedBanner();
    $bannerData = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->json('data');

    expect(array_filter(array_keys($bannerData), fn ($k) => str_contains($k, '.__media')))->toBe([]);
});

it('carries the sibling on list rows for the card-bound cover', function () {
    $row = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/galleries')
            ->json('data'),
    )->firstWhere('id', $this->gallery->id);

    expect($row['cover.__media'][0]['url'])->toBeString()
        // photos is not a card field — the list row must NOT carry it (the
        // card whitelist rule).
        ->and($row)->not->toHaveKey('photos.__media');
});

// P14 final review, Finding 2: `cover` is an UNDOTTED card path, so it never
// appears in `$card->relationPaths()` (dotted paths only) — without an
// explicit `media` eager load, index()'s card-bound leading image lazy-loads
// `media` once per row. ListEndpointTest already pins the same shape of
// query-count assertion for the dotted-relation case.
it('eager loads media so the card-bound cover does not N+1 on the list endpoint', function () {
    for ($i = 0; $i < 20; $i++) {
        $gallery = Gallery::create(['name' => "Trip {$i}"]);
        $gallery->addMediaFromString(fakePngBytes())->usingFileName('cover.jpg')->toMediaCollection('cover');
    }

    DB::enableQueryLog();
    $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/galleries')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Without the eager load this is 20+ queries (one `media` lookup per row).
    expect($queries)->toBeLessThan(10);
});
