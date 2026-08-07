<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\ChartWidget;

class RevenueChartWidget extends ChartWidget
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
                ['label' => 'Revenue', 'data' => [120, 340, 210]],
                // A dataset with no numeric data must be DROPPED with a
                // warning, not published as garbage — see the spec.
                ['label' => 'Broken', 'data' => 'not-a-list'],
            ],
        ];
    }
}
