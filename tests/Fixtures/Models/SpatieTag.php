<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Spatie\Tags\Tag as BaseTag;

/**
 * P15: the existing fixture `Tag` model (plain, `banners` pivot) keeps the
 * `tags` table it already owns — this class is `spatie/laravel-tags`'s own
 * tag model, remapped onto a `spatie_tags` table to avoid the collision.
 * Registered via `config('tags.tag_model', SpatieTag::class)` in TestCase's
 * environment setup, per the plan's collision ruling.
 */
class SpatieTag extends BaseTag
{
    protected $table = 'spatie_tags';
}
