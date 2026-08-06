<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Policies;

use Gait\FilamentMobile\Tests\Fixtures\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;

class PostPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $user?->name !== 'restricted';
    }

    public function view(?Authenticatable $user, Post $post): bool
    {
        return $this->viewAny($user);
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Denies everyone, always — it pins the capability semantics of the
     * panel document's resource-level `permissions` block, which reports
     * `update: true` regardless because the answer belongs to a record.
     */
    public function update(?Authenticatable $user, Post $post): bool
    {
        return false;
    }

    /**
     * Depends on the record, not just the user — the ordinary shape of a real
     * policy. It is what makes the resource-level `delete: true` a capability
     * and the record-level answer the truth.
     */
    public function delete(?Authenticatable $user, Post $post): bool
    {
        return $this->viewAny($user) && ! $post->published;
    }
}
