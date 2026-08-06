<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BrokenInfolistResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Illuminate\Support\Facades\Log;

/**
 * Regression suite for the second pilot defect: schema *construction* was
 * unguarded, so one resource whose infolist could not be built without a
 * record turned the whole /schema document into a 500.
 */
beforeEach(function () {
    config()->set('filament-mobile.resources', [
        PostResource::class,
        BannerResource::class,
        BrokenInfolistResource::class,
    ]);
});

it('confirms the fixture really does throw during schema construction', function () {
    // If Filament ever stops evaluating visible() inside getComponents(), this
    // fails and tells the next reader that the fixture — not the guard — is
    // what went stale. Every other test in this file depends on it throwing.
    expect(fn () => BrokenInfolistResource::infolist(\Filament\Schemas\Schema::make())->getComponents())
        ->toThrow(TypeError::class);
});

it('serves the schema with the other resources intact when one cannot be built', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk();

    expect(array_column($response->json('resources'), 'key'))
        ->toContain('posts', 'banners');
});

it('records a warning naming the resource and the schema that failed', function () {
    $warnings = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->json('_warnings');

    $broken = array_values(array_filter(
        $warnings,
        static fn (array $w): bool => $w['resource'] === 'BrokenInfolistResource',
    ));

    expect($broken)->toHaveCount(1)
        ->and($broken[0]['component'])->toBe('infolist()')
        ->and($broken[0]['reason'])->toContain('could not build the infolist schema');
});

it('keeps the broken resource in the document with an empty infolist and a working form', function () {
    $resources = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->json('resources');

    $broken = collect($resources)->firstWhere('key', 'broken-infolists');

    // The failure is isolated to the schema that threw: the resource keeps its
    // card, sorts, permissions and its perfectly good form.
    expect($broken)->not->toBeNull()
        ->and($broken['infolist'])->toBe([])
        ->and($broken['form'])->not->toBeEmpty()
        ->and($broken['card']['title']['field'])->toBe('name');
});

it('serves the record endpoint, falling back to card fields and logging why', function () {
    Log::spy();

    $banner = seedBanner(name: 'Still served');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/broken-infolists/' . $banner->id)
        ->assertOk()
        ->assertJsonPath('data.name', 'Still served');

    // Degrading a detail screen silently is how this becomes a bug report
    // nobody can reproduce, so the fallback has to leave a trace.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'BrokenInfolistResource')
            && str_contains($message, 'could not build the infolist schema'))
        ->once();
});
