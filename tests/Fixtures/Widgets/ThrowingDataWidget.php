<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use RuntimeException;

class ThrowingDataWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        throw new RuntimeException('query exploded');
    }
}
