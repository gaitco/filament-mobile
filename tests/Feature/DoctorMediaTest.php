<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\ColumnCollisionResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MedialessCardResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MedialessResource;

/**
 * P14 Task 5: doctor's three medialibrary diagnostics — see
 * DoctorCommand::mediaProblems(). Each fixture is scoped to its own
 * beforeEach() config, the same isolation DoctorRelationsTest uses.
 *
 * All three are informational, never folded into $actionable in handle():
 * same reasoning as unresolvableCardPaths()/proseOnlyCardFields() — a
 * silently-null field is a defect in THIS SLICE's coverage of a legal
 * declaration, not a broken panel. The one already-actionable medialibrary
 * finding (a form UPLOAD on a model without HasMedia) is SchemaWalker's own
 * WalkWarnings entry surfacing through the pre-existing "Unsupported
 * components" section — MediaWalkerTest and DoctorCommandTest already cover
 * that path, so these fixtures deliberately use INFOLIST entries instead,
 * to isolate the two genuinely new findings from that pre-existing one.
 */
it('names a media field on a model without HasMedia', function () {
    config()->set('filament-mobile.resources', [MedialessResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('MedialessResource.photo: SpatieMediaLibraryImageEntry on a model without HasMedia — the field always reads empty')
        ->assertExitCode(0);
});

it('names a card slot bound to a media path on a model without HasMedia', function () {
    config()->set('filament-mobile.resources', [MedialessCardResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('MedialessCardResource: card field `photo` is bound to a media path on a model without HasMedia — the slot will always publish null')
        ->assertExitCode(0);
});

it('names a media field whose name collides with a real column', function () {
    config()->set('filament-mobile.resources', [ColumnCollisionResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('ColumnCollisionResource.name: SpatieMediaLibraryFileUpload collides with a real column of the same name — the media value will shadow the column in the payload')
        ->assertExitCode(0);
});
