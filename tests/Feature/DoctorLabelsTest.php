<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\DottedLabelNoticeResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;

/**
 * `tags_entry` slice: doctor's "Labels" section — see
 * `DoctorCommand::labelProblems()`. Purely informational, the same shape as
 * `DoctorTranslatableTest`: it names a real gap but never touches the walker's
 * own WalkWarnings, so it never moves the exit code.
 */
it('names a dotted form field with no custom label', function () {
    config()->set('filament-mobile.resources', [DottedLabelNoticeResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain("DottedLabelNoticeResource.category.name: label defaults to 'Name' — set ->label() to disambiguate on the phone")
        ->assertExitCode(0);
});

it('does not name a dotted infolist entry that declares its own label', function () {
    config()->set('filament-mobile.resources', [DottedLabelNoticeResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('category.slug');
});

it('prints no Labels heading section when every dotted field is clean', function () {
    // PostResource declares no dotted field at all.
    config()->set('filament-mobile.resources', [PostResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('Labels')
        ->assertExitCode(0);
});
