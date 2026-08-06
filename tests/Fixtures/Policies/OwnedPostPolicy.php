<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Policies;

use Gait\FilamentMobile\Tests\Fixtures\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A `view()` that does NOT delegate to `viewAny()` — the ordinary ownership
 * shape, and the one PostPolicy's delegation hid: every record test passed
 * while the detail endpoint never checked `viewAny` at all.
 *
 * Filament authorizes every resource page, ViewRecord included, on
 * `Resource::canAccess()` => `canViewAny()`, so a user this policy grants
 * `view` on a record but denies `viewAny` gets a 403 on the web panel and must
 * get one on the phone.
 */
class OwnedPostPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $user?->name === 'admin';
    }

    public function view(?Authenticatable $user, Post $post): bool
    {
        return true;
    }
}
