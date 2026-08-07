<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * A chart label genuinely unrenderable as a string — proves the warning
 * `stringify()` emits for a dropped label names "chart label", not "stat
 * value": the labels array is non-nullable, so `''` is the only thing
 * `getData()` can honestly claim happened, and the warning is the only
 * signal a developer gets that it was really a drop, not an empty label.
 */
class UnrenderableLabelChartWidget extends ChartWidget
{
    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return [
            'labels' => [new class
            {
                //
            }],
            'datasets' => [
                ['label' => 'Series', 'data' => [1]],
            ],
        ];
    }
}
