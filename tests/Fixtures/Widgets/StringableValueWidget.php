<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Stringable;

/**
 * A stat value that is an object rather than a scalar — a money value
 * object, in shape — proving `WidgetReader` honours `__toString()` instead
 * of dropping the real value to an empty string.
 */
class StringableValueWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Balance', new class implements Stringable
            {
                public function __toString(): string
                {
                    return '$1,340.00';
                }
            }),
        ];
    }
}
