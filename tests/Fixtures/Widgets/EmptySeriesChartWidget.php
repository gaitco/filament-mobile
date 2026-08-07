<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * A query that returned zero rows this period. A normal state, not a panel
 * bug — the dataset publishes as an empty series and nothing warns.
 */
class EmptySeriesChartWidget extends ChartWidget
{
    protected ?string $heading = 'Quiet week';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return [
            'labels' => [],
            'datasets' => [
                ['label' => 'Orders', 'data' => []],
            ],
        ];
    }
}
