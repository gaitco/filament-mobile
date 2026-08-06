<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    public $timestamps = false;
}
