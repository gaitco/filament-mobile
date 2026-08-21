<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\UndottedCaptionNoticeResource;

/**
 * P17 Task 2: doctor's one informational Translatable diagnostic — see
 * `DoctorCommand::translatableProblems()`. Purely informational, the same
 * shape as `DoctorTagsTest`'s column-collision case: it names a real
 * divergence but never the walker's own WalkWarnings, so it never touches
 * the exit code (0), unlike the two `DoctorTagsTest` cases that ride
 * alongside an actionable "Unsupported components" finding.
 */
it('names an undotted field bound to a translatable attribute', function () {
    config()->set('filament-mobile.resources', [UndottedCaptionNoticeResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('UndottedCaptionNoticeResource.caption: undotted field on a translatable attribute — mobile edits the panel\'s current locale only for this field')
        ->assertExitCode(0);
});
