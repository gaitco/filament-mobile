<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Some\Removed\ChartingPackage\BaseWidget;

/**
 * Extends a class from a package that is not installed, so merely
 * autoloading this file throws an `Error`. `class_exists()` on it must
 * degrade the widget, never 500 the dashboard or crash doctor.
 */
class BrokenParentWidget extends BaseWidget
{
}
