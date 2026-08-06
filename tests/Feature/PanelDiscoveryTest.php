<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\PanelRegistry;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SecretResource;
use Illuminate\Support\Facades\Log;

/**
 * The regression suite for the defect the pilot found: the package
 * discovered ZERO resources in every real application, and 100 tests stayed
 * green because every one of them sets `filament-mobile.resources` explicitly.
 *
 * Every test in this file therefore clears that config first. If it is ever
 * reintroduced here, these tests stop testing the thing they exist for.
 */

/**
 * Registers a panel the way an application's PanelProvider does.
 *
 * NOT via `Filament::registerPanel()`: that facade method is
 * `getFacadeApplication()->resolving(PanelRegistry::class, ...)`, which only
 * fires if the registry has not been resolved yet. In a real app the provider
 * runs before anything touches the registry; in a booted test it has already
 * been resolved, so the callback never runs and the panel is silently dropped.
 * The manager's own `registerPanel()` is what that callback ends up calling.
 *
 * @param  list<class-string>  $resources
 */
function registerPanelWith(array $resources): void
{
    config()->set('filament-mobile.resources', null);

    app('filament')->registerPanel(
        Panel::make()->default()->id('testing')->path('testing')->resources($resources),
    );
}

it('discovers the registered panel resources with no explicit resource config', function () {
    registerPanelWith([PostResource::class, BannerResource::class]);

    $registry = new ResourceRegistry();

    // The assertion that would have caught the bug. Before the fix this was
    // an empty array in every real application, and `doctor` reported a clean
    // bill of health on a panel it had never seen.
    expect(iterator_to_array($registry->allResourceClasses()))
        ->toBe([PostResource::class, BannerResource::class])
        ->and(array_keys($registry->mobileResources()))
        ->toBe([PostResource::class, BannerResource::class]);
});

it('resolves a route key against the panel, not the config list', function () {
    registerPanelWith([PostResource::class]);

    $found = (new ResourceRegistry())->findByKey('posts');

    expect($found)->not->toBeNull()
        ->and($found[0])->toBe(PostResource::class);
});

it('still hides a panel resource that declares no mobile()', function () {
    // Opt-in is the safety property, and it has to survive panel discovery:
    // reading resources from the panel must not start exposing all of them.
    registerPanelWith([PostResource::class, DiscoveryOptOutResource::class]);

    expect(array_keys((new ResourceRegistry())->mobileResources()))
        ->toBe([PostResource::class]);
});

it('serves the schema endpoint from the panel with no resource config', function () {
    registerPanelWith([PostResource::class, SecretResource::class]);

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->assertJsonPath('resources.0.key', 'posts');
});

it('logs why the panel could not be read rather than serving nothing in silence', function () {
    // No panel registered and no explicit list: the empty array this returns is
    // byte-identical to the README's guard trap and to a correctly-denied user.
    // Three causes, one symptom — the log line is the only thing that separates
    // them at 2am.
    config()->set('filament-mobile.resources', null);

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'could not read the Filament panel'),
    );

    expect(iterator_to_array((new ResourceRegistry())->allResourceClasses()))->toBe([]);
});

it('pins why getPanel(isStrict: false) can never be used for discovery', function () {
    registerPanelWith([PostResource::class]);

    // PanelRegistry::get() opens with `if ($id === null) { return null; }`, so
    // the no-argument call resolves nothing in ANY context — HTTP or console.
    // This is the exact trap the fix walked out of; if a later refactor
    // "simplifies" back to it, this fails and says why.
    expect(\Filament\Facades\Filament::getPanel(isStrict: false))->toBeNull()
        ->and(app(PanelRegistry::class)->getDefault()->getId())->toBe('testing');
});

/** A panel resource with no mobile(), used only by the opt-in test above. */
class DiscoveryOptOutResource extends \Filament\Resources\Resource
{
    protected static ?string $model = \Gait\FilamentMobile\Tests\Fixtures\Models\Post::class;
}
