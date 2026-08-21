<?php

declare(strict_types=1);

use Gait\MobileCore\SafeEvaluator;
use Gait\MobileCore\WalkWarnings;

it('returns the read value when the closure succeeds', function () {
    $warnings = new WalkWarnings();
    $evaluator = new SafeEvaluator($warnings);

    $result = $evaluator->value(fn () => 'الاسم', null, 'UserResource', 'name', 'label');

    expect($result)->toBe('الاسم')
        ->and($warnings->isEmpty())->toBeTrue();
});

it('falls back and records a warning when the closure throws', function () {
    $warnings = new WalkWarnings();
    $evaluator = new SafeEvaluator($warnings);

    $result = $evaluator->value(
        fn () => throw new RuntimeException('needs a Livewire context'),
        'fallback',
        'UserResource',
        'country_id',
        'options',
    );

    expect($result)->toBe('fallback')
        ->and($warnings->all())->toHaveCount(1)
        ->and($warnings->all()[0]['resource'])->toBe('UserResource')
        ->and($warnings->all()[0]['component'])->toBe('country_id')
        ->and($warnings->all()[0]['reason'])->toContain('options')
        ->and($warnings->all()[0]['reason'])->toContain('needs a Livewire context');
});

it('survives an Error, not only an Exception', function () {
    $warnings = new WalkWarnings();
    $evaluator = new SafeEvaluator($warnings);

    // A closure written for a Livewire context commonly fails with a TypeError
    // or a call-on-null Error rather than a tidy Exception.
    $result = $evaluator->value(
        fn () => throw new TypeError('null given'),
        'fallback',
        'R',
        'c',
        'label',
    );

    expect($result)->toBe('fallback')
        ->and($warnings->all())->toHaveCount(1);
});

it('keeps every warning rather than collapsing duplicates', function () {
    $warnings = new WalkWarnings();
    $warnings->add('R', 'a', 'reason');
    $warnings->add('R', 'b', 'reason');

    expect($warnings->all())->toHaveCount(2)
        ->and($warnings->isEmpty())->toBeFalse();
});
