<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Policies;

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * P9: every write ability denied, every read ability granted — the policy
 * shape that isolates a relation write endpoint's OWN gate. With viewAny/view
 * allowed, the read-side gates (parent show() authorization, the relation
 * manager's canViewForRecord) all pass, so a 403 can only have come from the
 * per-operation check the test is pointed at.
 *
 * Registered per test with `Gate::policy(Banner::class, …)`, never in
 * TestCase: Banner deliberately carries no policy everywhere else in this
 * suite, and a global registration would turn every existing write test red
 * for reasons unrelated to it.
 */
class NoWritesBannerPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, Banner $banner): bool
    {
        return true;
    }

    public function create(?Authenticatable $user): bool
    {
        return false;
    }

    public function update(?Authenticatable $user, Banner $banner): bool
    {
        return false;
    }

    public function delete(?Authenticatable $user, Banner $banner): bool
    {
        return false;
    }
}
