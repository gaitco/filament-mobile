<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P18 Task 1: the fixture model behind `SlideResource`/`SlideDescResource`/
 * `SlidePivotResource` — three tables sharing one model, so
 * `ReorderDeclarationTest` proves `column`/`direction`/dotted-column reads
 * off the TABLE declaration, not the model.
 */
class Slide extends Model
{
    protected $guarded = [];
}
