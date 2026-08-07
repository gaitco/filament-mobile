<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Widgets\DeniedWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\OrdersOverviewWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\RevenueChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\ThrowingDataWidget;

// Same reset SchemaEndpointTest carries, for the same reason: the production
// test flips the app environment and never flips it back, so Testbench's
// `migrate:rollback` teardown would hit ConfirmableTrait's production gate.
afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('serves the opted-in widgets in configuration order', function () {
    config()->set('filament-mobile.widgets', [
        RevenueChartWidget::class,
        OrdersOverviewWidget::class,
    ]);

    $widgets = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->json('widgets');

    // Configuration order IS publication order — the client renders what it
    // is given, top to bottom.
    expect(array_column($widgets, 'type'))->toBe(['chart', 'stats']);
});

it('answers an empty list when no widget is opted in', function () {
    config()->set('filament-mobile.widgets', []);

    // The default test environment is 'testing', not 'production', so
    // `_warnings` (empty here) is still present — same as /schema. Assert
    // it exactly rather than dropping to a partial match, so an accidental
    // extra top-level key would fail this test.
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->assertExactJson(['widgets' => [], 'direction' => 'ltr', '_warnings' => []]);
});

it('publishes direction the same way /schema does, sharing one body', function () {
    // Task 4b: the dashboard's own endpoint carries `direction`, exactly the
    // closed 'ltr'/'rtl' set PanelSchemaBuilder::direction() already answers
    // for /schema — same method, not a second copy of the normalising rule.
    config()->set('filament-mobile.widgets', []);
    app()->setLocale('ar');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->assertJsonPath('direction', 'rtl');
});

it('omits a widget the user may not view', function () {
    config()->set('filament-mobile.widgets', [
        DeniedWidget::class,
        OrdersOverviewWidget::class,
    ]);

    $widgets = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->json('widgets');

    expect($widgets)->toHaveCount(1)
        ->and($widgets[0]['type'])->toBe('stats');
});

it('survives a widget whose query throws, and still serves its siblings', function () {
    // The whole reason this endpoint degrades per widget: one bad query must
    // not cost every user the entire dashboard.
    config()->set('filament-mobile.widgets', [
        ThrowingDataWidget::class,
        OrdersOverviewWidget::class,
    ]);

    $widgets = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->json('widgets');

    expect($widgets)->toHaveCount(1);
});

it('requires authentication', function () {
    $this->getJson('/api/mobile-panel/dashboard')->assertUnauthorized();
});

it('includes _warnings outside production', function () {
    // Same contract as /schema's `_warnings`, for the same reason.
    config()->set('filament-mobile.widgets', [ThrowingDataWidget::class]);
    app()->detectEnvironment(fn () => 'local');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->assertJsonStructure(['widgets', '_warnings']);
});

it('omits _warnings in production so a phone never receives diagnostics', function () {
    config()->set('filament-mobile.widgets', [ThrowingDataWidget::class]);
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->assertJsonMissingPath('_warnings');
});
