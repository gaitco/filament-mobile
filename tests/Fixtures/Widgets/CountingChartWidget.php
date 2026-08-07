<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * Counts its own `getData()` invocations, so a test can prove the reader
 * goes through Filament's `getCachedData()` memo instead of re-running the
 * widget's queries a second time per request.
 */
class CountingChartWidget extends ChartWidget
{
    public static int $dataReads = 0;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        self::$dataReads++;

        return [
            'labels' => ['Jan'],
            'datasets' => [
                ['label' => 'Reads', 'data' => [1]],
            ],
        ];
    }
}
