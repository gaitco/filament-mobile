<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DeniedWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return false;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Secret', 1),
        ];
    }
}
