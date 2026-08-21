<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Tags\HasTags;

/**
 * P15: the first fixture with `HasTags`, behind the `spatie/laravel-tags`
 * dev dependency. Its tags resolve onto the remapped `spatie_tags` table
 * (see `SpatieTag`), not the existing plain fixture `Tag`'s `tags` table.
 */
class Article extends Model
{
    use HasTags;

    protected $guarded = [];

    /**
     * P15 Task 4: overrides `HasTags::tags()` only to pin the pivot's
     * RELATED key to `tag_id` explicitly. Left to Eloquent's own inference,
     * a related model named `SpatieTag` (renamed to dodge the fixture `Tag`
     * collision — see that class's docblock) would resolve this to
     * `spatie_tag_id`; `HasTags::syncTagIds()` (`syncTagsWithType()`'s
     * write path, exercised by a typed `SpatieTagsInput`) hardcodes the
     * literal column name `tag_id` in a raw join with no way to configure
     * it otherwise, so the two would silently disagree on write. Every
     * other line of this relation (`using()`/`ordered()`) is copied
     * verbatim from `HasTags::tags()`.
     */
    public function tags(): MorphToMany
    {
        return $this
            ->morphToMany(self::getTagClassName(), $this->getTaggableMorphName(), $this->getTaggableTableName(), null, 'tag_id')
            ->using($this->getPivotModelClassName())
            ->ordered();
    }
}
