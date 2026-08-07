<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersOverviewWidget extends StatsOverviewWidget
{
    // The web panel renders both — mobile must publish them too.
    protected ?string $heading = 'Store overview';

    protected ?string $description = 'Orders at a glance';

    protected function getStats(): array
    {
        return [
            // Every published field at once, so one fixture proves the whole
            // node shape rather than needing a widget per key.
            Stat::make('Orders this week', 1340)
                ->description('12% increase')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart([7, 12, 9, 15, 22]),
            // The minimum a Stat can be: no description, no colour, no chart.
            Stat::make('Refunds', 3),
        ];
    }
}
