<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\RankedSlideResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideBrokenResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlidePivotResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideResource;

/**
 * P18 Task 5: doctor's three reorder diagnostics — see
 * DoctorCommand::reorderProblems(). Each fixture is scoped to its own
 * beforeEach() config, the same isolation DoctorMediaTest uses.
 *
 * Diagnostics (a) and (c) are informational — a resource declaration is
 * legal, this slice simply does not support it on mobile.
 * Diagnostic (b) reports a drift between table() and model config,
 * which is also informational — the panel declares both, and neither is
 * wrong, only surprising.
 *
 * All three produce a "Reordering" section heading printed only when
 * at least one applies. SlideResource produces no Reordering heading.
 */
it('names a reorder column that does not exist on the table', function () {
    config()->set('filament-mobile.resources', [SlideBrokenResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('SlideBrokenResource: table reorderable on [missing_column] but slides has no such column')
        ->assertExitCode(0);
});

it('names a reorder column mismatch with the model\'s Spatie sortable column', function () {
    config()->set('filament-mobile.resources', [RankedSlideResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('RankedSlideResource: table reorders [position] but the model\'s Spatie sortable column is [rank]')
        ->assertExitCode(0);
});

it('names a reorderable on a pivot column', function () {
    config()->set('filament-mobile.resources', [SlidePivotResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('SlidePivotResource: reorderable on a pivot column [pivot.position] — not offered on mobile')
        ->assertExitCode(0);
});

it('produces no Reordering heading for a clean resource', function () {
    config()->set('filament-mobile.resources', [SlideResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('Reordering')
        ->assertExitCode(0);
});
