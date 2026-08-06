<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Policies;

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * Untyped policy methods with an implicit-null return path — legal PHP, common
 * in real policies, and the exact shape that errs *open* if the record-level
 * authorizer reads null as "nobody defined this ability". Laravel's own
 * `Gate::check()` denies on null, so anything looser here would make mobile
 * more permissive than the web panel.
 *
 * Bound to Banner inside individual tests only: Banner is otherwise policy-free,
 * which is what the no-policy tests rely on.
 */
class ImplicitNullPolicy
{
    public function view(mixed $user, Banner $banner)
    {
        if ($user?->name !== 'blocked') {
            return true;
        }

        // Falls off the end → null.
    }

    public function update(mixed $user, Banner $banner)
    {
        // Falls off the end → null.
    }

    public function delete(mixed $user, Banner $banner)
    {
        // Falls off the end → null.
    }
}
