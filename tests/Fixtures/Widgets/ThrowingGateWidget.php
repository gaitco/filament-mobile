<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use RuntimeException;

class ThrowingGateWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        throw new RuntimeException('gate exploded');
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Unreachable', 1),
        ];
    }
}
