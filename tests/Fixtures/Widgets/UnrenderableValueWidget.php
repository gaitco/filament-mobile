<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A stat value genuinely unrenderable as a string — no `__toString()`, not
 * `Htmlable`, not a backed enum. This must drop to `null` with a warning,
 * never to a silent `""`.
 */
class UnrenderableValueWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Mystery', new class
            {
                //
            }),
        ];
    }
}
