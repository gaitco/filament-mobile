<?php

declare(strict_types=1);

use Gait\FilamentMobile\Dashboard\WidgetReader;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\BrokenParentWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\CountingChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\DeniedWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\EmptySeriesChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\HtmlLabelWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\MountDependentChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\NumericStringChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\OrdersOverviewWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\RevenueChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\StringableValueWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\ThrowingDataWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\ThrowingGateWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\UnrenderableLabelChartWidget;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\UnrenderableValueWidget;
use Gait\MobileCore\WalkWarnings;

function reader(?WalkWarnings $warnings = null): WidgetReader
{
    return new WidgetReader($warnings ?? new WalkWarnings());
}

it('reads a stats widget into the wire shape', function () {
    $node = reader()->read(OrdersOverviewWidget::class);

    expect($node['type'])->toBe('stats')
        // The web panel renders the widget's heading and description — the
        // phone must receive them, not a hardcoded null.
        ->and($node['heading'])->toBe('Store overview')
        ->and($node['description'])->toBe('Orders at a glance')
        ->and($node['stats'])->toHaveCount(2);

    [$first, $second] = $node['stats'];

    expect($first['label'])->toBe('Orders this week')
        // Always a string: the panel owns the formatting, not the phone.
        ->and($first['value'])->toBe('1340')
        ->and($first['description'])->toBe('12% increase')
        ->and($first['descriptionIcon'])->toBe('heroicon-m-arrow-trending-up')
        ->and($first['color'])->toBe('success')
        ->and($first['chart'])->toBe([7, 12, 9, 15, 22]);

    expect($second['label'])->toBe('Refunds')
        ->and($second['value'])->toBe('3')
        ->and($second['description'])->toBeNull()
        ->and($second['chart'])->toBeNull();
});

it('reads a chart widget, dropping a dataset whose data is not a list of numbers', function () {
    $warnings = new WalkWarnings();
    $node = reader($warnings)->read(RevenueChartWidget::class);

    expect($node['type'])->toBe('chart')
        ->and($node['chartType'])->toBe('line')
        ->and($node['heading'])->toBe('Revenue')
        ->and($node['labels'])->toBe(['Jan', 'Feb', 'Mar'])
        ->and($node['datasets'])->toHaveCount(1)
        ->and($node['datasets'][0]['label'])->toBe('Revenue')
        ->and($node['datasets'][0]['data'])->toBe([120, 340, 210]);

    // Dropped, but never silently: the panel author has a real bug here.
    expect($warnings->isEmpty())->toBeFalse();
});

it('omits a widget the user may not view', function () {
    expect(reader()->read(DeniedWidget::class))->toBeNull();
});

it('omits a widget whose gate throws, and warns', function () {
    // Fail closed: a gate that cannot answer refuses, the rule this package
    // applies to every gate it evaluates.
    $warnings = new WalkWarnings();

    expect(reader($warnings)->read(ThrowingGateWidget::class))->toBeNull()
        ->and($warnings->isEmpty())->toBeFalse();
});

it('omits a widget whose data throws, and warns — the dashboard survives', function () {
    $warnings = new WalkWarnings();

    expect(reader($warnings)->read(ThrowingDataWidget::class))->toBeNull()
        ->and($warnings->isEmpty())->toBeFalse();
});

it('omits a class that does not exist rather than throwing', function () {
    expect(reader()->read('App\\Nope\\NoSuchWidget'))->toBeNull();
});

it('reports configuration problems for doctor', function () {
    expect(reader()->problems('App\\Nope\\NoSuchWidget'))->not->toBeEmpty()
        ->and(reader()->problems(OrdersOverviewWidget::class))->toBeEmpty();
});

it('honours __toString on a stat value instead of losing it to an empty string', function () {
    $node = reader()->read(StringableValueWidget::class);

    expect($node['stats'][0]['value'])->toBe('$1,340.00');
});

it('drops a genuinely unrenderable stat value to null, but never silently', function () {
    $warnings = new WalkWarnings();
    $node = reader($warnings)->read(UnrenderableValueWidget::class);

    expect($node['stats'][0]['value'])->toBeNull()
        ->and($warnings->isEmpty())->toBeFalse();
});

it('calls mount() before reading a widget whose data depends on mounted state', function () {
    $node = reader()->read(MountDependentChartWidget::class);

    expect($node['labels'])->toBe(['mounted'])
        ->and($node['datasets'][0]['label'])->toBe('mounted');
});

it('reports a widget whose data cannot be read headlessly as a doctor problem', function () {
    $problems = reader()->problems(ThrowingDataWidget::class);

    expect($problems)->not->toBeEmpty()
        ->and($problems[0])->toContain(ThrowingDataWidget::class);
});

it('does not report a canView() denial as a doctor problem — that is the system working', function () {
    expect(reader()->problems(DeniedWidget::class))->toBeEmpty();
});

it('runs each chart widget\'s queries once per request, not twice', function () {
    // mount() already memoizes getData() into getCachedData()'s cache while
    // computing the data checksum. Reading getData() again re-runs every
    // query; the reader must go through the memo.
    CountingChartWidget::$dataReads = 0;

    $node = reader()->read(CountingChartWidget::class);

    expect($node['datasets'][0]['data'])->toBe([1])
        ->and(CountingChartWidget::$dataReads)->toBe(1);
});

it('accepts numeric-string chart data — MySQL DECIMAL aggregates — as floats', function () {
    $warnings = new WalkWarnings();
    $node = reader($warnings)->read(NumericStringChartWidget::class);

    expect($node['datasets'])->toHaveCount(1)
        ->and($node['datasets'][0]['data'])->toBe([120.5, 340.25, 210.0])
        ->and($warnings->isEmpty())->toBeTrue();
});

it('publishes an empty series as a normal state, never a warned drop', function () {
    // Zero rows this period is a fact about the data, not a panel bug —
    // and it is what Dart's parser produces for the same payload.
    $warnings = new WalkWarnings();
    $node = reader($warnings)->read(EmptySeriesChartWidget::class);

    expect($node['datasets'])->toHaveCount(1)
        ->and($node['datasets'][0]['data'])->toBe([])
        ->and($warnings->isEmpty())->toBeTrue();
});

it('warns when an HTML stat label is dropped — the client discards the whole stat', function () {
    $warnings = new WalkWarnings();
    $node = reader($warnings)->read(HtmlLabelWidget::class);

    expect($node['stats'][0]['label'])->toBe('')
        ->and($warnings->isEmpty())->toBeFalse();
});

it('degrades a widget whose autoload throws instead of 500ing the dashboard', function () {
    // The file exists but extends a class from a removed package: merely
    // class_exists()ing it throws an Error during include.
    expect(reader()->read(BrokenParentWidget::class))->toBeNull();
});

it('reports a widget whose autoload throws as a doctor problem, not a crash', function () {
    $problems = reader()->problems(BrokenParentWidget::class);

    expect($problems)->not->toBeEmpty();
});

it('reports a dataset that would be dropped as a doctor problem', function () {
    // read() drops the dataset with only a _warnings entry — invisible in
    // production. doctor must run the same normalisation and say so.
    $problems = reader()->problems(RevenueChartWidget::class);

    expect($problems)->not->toBeEmpty()
        ->and(implode(' ', $problems))->toContain('dataset');
});

it('warns with the right noun when a chart label is unrenderable, not "stat value"', function () {
    $warnings = new WalkWarnings();
    $node = reader($warnings)->read(UnrenderableLabelChartWidget::class);

    expect($node['labels'])->toBe([''])
        ->and($warnings->isEmpty())->toBeFalse();

    $reasons = array_column($warnings->all(), 'reason');

    expect($reasons)->toContain(
        'a chart label of type class@anonymous has no renderable string form and was dropped.',
    );
});
