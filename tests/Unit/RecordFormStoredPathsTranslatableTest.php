<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Notice;
use Gait\FilamentMobile\Write\RecordForm;

/**
 * P17: pins `storedPaths()` against a REAL `Spatie\Translatable\HasTranslations`
 * model (`Notice`), not the fake `TranslatableRecord` in
 * `TranslatableSerializationTest` — see `Notice`'s docblock for why a fake
 * can't prove this half.
 *
 * `storedPaths()` reads `$record->getAttribute($attribute)` and keeps the
 * result only `if (is_array($value))`. On `Notice`, `getAttribute('caption')`
 * resolves through Spatie's own accessor, which hands back the CURRENT
 * LOCALE'S STRING — never an array — so the existing code contributes
 * NOTHING for `caption`, and the locale-preserving merge
 * (`fillMissingPaths()`) has nothing to refill from. This is the RED
 * precondition: it must fail against unmodified `storedPaths()`.
 */
it('reads a translatable attribute through getTranslations(), not the lying accessor', function () {
    $notice = Notice::create([
        'title' => 'Weather',
        'caption' => ['ar' => 'مرحبا', 'en' => 'Hello'],
    ]);

    expect(RecordForm::storedPaths($notice, ['caption.ar', 'caption.en', 'title']))
        ->toBe([
            'caption.ar' => 'مرحبا',
            'caption.en' => 'Hello',
        ]);
});
