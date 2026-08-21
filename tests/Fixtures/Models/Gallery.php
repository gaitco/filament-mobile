<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * P14: the first fixture with a real HasMedia model, behind the medialibrary
 * dev dependency. `photos` is the multiple collection, `cover` the single
 * one — the exact names later tasks' serializer/write tests instantiate
 * against, so they stay as declared here.
 */
class Gallery extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
        $this->addMediaCollection('cover')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Conversions are queued by default; keep them synchronous in tests.
        $this->addMediaConversion('thumb')->width(96)->height(96)->nonQueued();
    }
}
