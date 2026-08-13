<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Widgets\OrdersOverviewWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\RevenueChartWidget;

// Golden file for the dashboard payload, mirroring what ContractSnapshotTest
// does for /schema: this is what the real endpoint actually emits for the
// real fixture widgets, read by Task 6's Dart contract test. Regenerate with
// UPDATE_SNAPSHOTS=1.

// Same reset DashboardEndpointTest and SchemaEndpointTest carry, for the same
// reason: the production env-flip below never flips back on its own, and
// Testbench's `migrate:rollback` teardown would hit ConfirmableTrait's
// production gate otherwise.
afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('matches the committed dashboard contract snapshot', function () {
    // Through the real endpoint, not WidgetReader directly: the `widgets`
    // envelope, the config-order iteration and the null-filtering all live in
    // DashboardController, not in WidgetReader — which only ever produces one
    // widget's node. A fixture built by hand-wrapping WidgetReader calls would
    // pin the reader's per-widget shape while leaving the controller's
    // envelope unchecked, which is exactly the gap this fixture exists to
    // close.
    config()->set('filament-mobile.widgets', [
        OrdersOverviewWidget::class,
        RevenueChartWidget::class,
    ]);

    // production, so `_warnings` is naturally absent from the real response —
    // never stripped by hand — same idiom as
    // DashboardEndpointTest's "omits _warnings in production" case.
    app()->detectEnvironment(fn () => 'production');

    $body = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/dashboard')
        ->assertOk()
        ->json();

    $json = json_encode(
        $body,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";

    if (getenv('UPDATE_SNAPSHOTS') === '1') {
        file_put_contents(contractPath('dashboard.json'), $json);
    }

    expect(contractPath('dashboard.json'))->toBeReadableFile()
        ->and($json)->toBe(file_get_contents(contractPath('dashboard.json')));
});
