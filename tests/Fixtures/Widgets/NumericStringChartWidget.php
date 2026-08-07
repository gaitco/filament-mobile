<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * MySQL PDO returns strings for DECIMAL aggregates — `SUM(total)` on a money
 * column arrives as `"120.50"`, and Chart.js renders it on the web. Mobile
 * must accept it too, not drop the whole dataset.
 */
class NumericStringChartWidget extends ChartWidget
{
    protected ?string $heading = 'Revenue';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return [
            'labels' => ['Jan', 'Feb', 'Mar'],
            'datasets' => [
                ['label' => 'Revenue', 'data' => ['120.50', '340.25', '210.00']],
            ],
        ];
    }
}
