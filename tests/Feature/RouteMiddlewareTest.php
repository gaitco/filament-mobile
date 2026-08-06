<?php

declare(strict_types=1);

use Gait\FilamentMobile\FilamentMobileServiceProvider;
use Gait\FilamentMobile\Tests\Fixtures\Middleware\LocaleProbe;
use Illuminate\Support\Facades\Route;

// Without a host middleware group the endpoints run outside the host's stack,
// so its SetLocale never fires and every response serialises in APP_LOCALE.
// The pilot measured that: schema-ar.json and schema-en.json came back
// byte-identical.

it('applies the configured middleware ahead of the auth guard', function () {
    $middleware = Route::getRoutes()->getByName('filament-mobile.schema')->middleware();

    expect($middleware)->toBe(['api', 'auth']);
});

it('runs host middleware on every mobile endpoint', function () {
    config()->set('filament-mobile.middleware', ['api', LocaleProbe::class]);
    $this->app->register(new FilamentMobileServiceProvider($this->app), true);

    $user = makeUser('admin');
    seedBanner();

    foreach (['/api/mobile-panel/schema', '/api/mobile-panel/banners'] as $path) {
        $this->actingAs($user)
            ->withHeader('X-Locale', 'ar')
            ->getJson($path)
            ->assertOk()
            ->assertHeader('X-Probe-Locale', 'ar');
    }
});

it('keeps the auth guard when a host configures its own middleware', function () {
    config()->set('filament-mobile.middleware', [LocaleProbe::class]);
    $this->app->register(new FilamentMobileServiceProvider($this->app), true);

    $this->getJson('/api/mobile-panel/schema')->assertUnauthorized();
});

it('resolves POST {resource}/options from the live route collection, not the file', function () {
    // P1 shipped a wildcard segment that claimed every URI ending in `/schema`
    // in the host app and had to be reverted as a Critical, so route order is
    // asserted against the router itself rather than read off the source.
    $matched = Route::getRoutes()->match(
        Illuminate\Http\Request::create('/api/mobile-panel/banners/options', 'POST'),
    );

    expect($matched->getName())->toBe('filament-mobile.options');
});

it('still resolves POST {resource}/state, which shares the same segment shape', function () {
    // The neighbouring literal. Adding `options` above `{resource}/{record}`
    // must not have displaced it.
    $matched = Route::getRoutes()->match(
        Illuminate\Http\Request::create('/api/mobile-panel/banners/state', 'POST'),
    );

    expect($matched->getName())->toBe('filament-mobile.state');
});
