<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $guarded = [];

    /** @return HasMany<Banner, $this> */
    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class);
    }
}
