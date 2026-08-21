<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * P17: the first fixture with the REAL `Spatie\Translatable\HasTranslations`
 * trait, behind the dev-only `spatie/laravel-translatable` dependency.
 *
 * `TranslatableSerializationTest`'s `TranslatableRecord` fake proves the
 * accessor's lying shape (current-locale string, not the map) is real — but a
 * fake can't prove the WRITE-side fix, because Spatie's own `setTranslations()`
 * merge lives on the model, not in this package, and would silently mask a
 * broken `storedPaths()` the same way it already has (see the scouting note).
 * Only a genuine trait-backed model, saved through the real endpoints, pins
 * that the merge this package is responsible for — not Spatie's — is what did
 * the preserving.
 */
class Notice extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['caption'];
}
