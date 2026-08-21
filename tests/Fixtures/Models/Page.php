<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * P16: the first fixture with `HasSlug`, behind the dev-only
 * spatie/laravel-sluggable dependency. `title` is the source field, `slug`
 * the generated one — the exact names the pinning test in
 * `SluggableTest.php` asserts the vendor's three documented semantics
 * against (see `docs/superpowers/specs/2026-08-21-p16-sluggable-design.md`).
 */
class Page extends Model
{
    use HasSlug;

    protected $guarded = [];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug');
    }
}
