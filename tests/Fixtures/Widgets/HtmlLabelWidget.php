<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

/**
 * A stat label that renders HTML. It cannot travel to a phone, so it drops
 * — but the drop must WARN, because the Dart side then skips the whole stat
 * (`label.isEmpty`) and a silent drop leaves zero developer signal.
 */
class HtmlLabelWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(new HtmlString('<strong>Orders</strong>'), 7),
        ];
    }
}
