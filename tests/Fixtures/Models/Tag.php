<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsToMany<Banner, $this> */
    public function banners(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class);
    }

    /**
     * Deliberately not `id`. `BannerResource`'s `TagsRelationManager` is the
     * package's only BelongsToMany relation fixture, and this is what makes
     * it also the fixture proving `PanelSchemaBuilder::relations()` publishes
     * the RELATED model's own route key rather than assuming `id` — every
     * other relation fixture's child model happens to use the default, which
     * would pass even a hardcoded `'id'`.
     */
    public function getRouteKeyName(): string
    {
        return 'name';
    }
}
