<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\TableWidget;

/**
 * A widget kind this slice does not support — a `problems()`/doctor case.
 * TableWidget subclasses cleanly without a `table()` definition, so no
 * fallback to a bare `Widget` subclass was needed.
 */
class PlainTableWidget extends TableWidget
{
}
