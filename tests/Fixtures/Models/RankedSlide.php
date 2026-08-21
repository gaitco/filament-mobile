<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * P18 Task 5: a Slide model with Spatie sortable configured to use `rank`
 * as the order column. Used by RankedSlideResource to test the diagnostic
 * when the table's reorderable() column differs from the model's sortable
 * order_column_name.
 */
class RankedSlide extends Model implements Sortable
{
    use SortableTrait;

    protected $table = 'slides';

    protected $guarded = [];

    public $sortable = ['order_column_name' => 'rank', 'sort_when_creating' => false];
}
