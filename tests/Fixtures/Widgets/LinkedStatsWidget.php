<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The three URL shapes a stat can carry: this panel's own resource (the one
 * shape that becomes a mobile target), a foreign host that merely ends in a
 * matching slug, and no URL at all.
 */
class LinkedStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Companies', 12)->url('/admin/companies'),
            Stat::make('Elsewhere', 4)->url('https://example.com/admin/companies'),
            Stat::make('Plain', 7),
        ];
    }
}
