<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $guarded = [];

    /** @return BelongsToMany<Banner, $this> */
    public function banners(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class);
    }
}
