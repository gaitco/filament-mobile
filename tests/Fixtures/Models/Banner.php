<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Banner extends Model
{
    protected $guarded = [];

    /**
     * The first cast exists for one reason: raw and cast
     * forms of `options` differ observably inside a schema closure (a JSON
     * string vs an array), so a form seeded from `getAttributes()` behaves
     * differently from one seeded from `attributesToArray()`.
     *
     * `caption` is the second, and duller: a JSON column two Hidden fields
     * address as `caption.ar` and `caption.en`, so a default written under a
     * literal dotted key is observable as a missing column rather than a pass.
     *
     * `plain_multi` is the third: a LIST-valued default, which is the one shape
     * a recursive merge corrupts by index rather than replacing.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'options' => 'array',
        'caption' => 'array',
        'plain_multi' => 'array',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Minimal by design: this fixture exists to be introspected by
     * FieldPersistence, not exercised by a write test — the write path never
     * calls saveRelationships(), so a pivot row is never expected here.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
