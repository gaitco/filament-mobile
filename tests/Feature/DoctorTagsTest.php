<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\ColumnCollisionArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TaglessCardCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TaglessCompanyResource;

/**
 * P15 Task 4: doctor's three Spatie tags diagnostics — see
 * DoctorCommand::tagProblems(). Each fixture is scoped to its own
 * beforeEach() config, the same isolation DoctorMediaTest uses.
 *
 * Unlike medialibrary, `SpatieTagsInput` has no infolist-entry twin, so
 * diagnostics (1) and (3) below are ALSO already actionable through the
 * pre-existing "Unsupported components" section — SchemaWalker's own
 * WalkWarnings entry (Task 2's tagsFailClosed()) for the exact same
 * traitless-model shape. Those two tests assert exit code 1 for that reason,
 * asserting only that this section's own line is present alongside it.
 * Diagnostic (2) — the column collision — has no competing signal and
 * asserts exit code 0, the same as DoctorMediaTest's collision case.
 */
it('names a spatie tags field on a model without HasTags', function () {
    config()->set('filament-mobile.resources', [TaglessCompanyResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('TaglessCompanyResource.tags: SpatieTagsInput on a model without HasTags — the field always reads empty')
        ->assertExitCode(1);
});

it('names a card slot bound to a tags path on a model without HasTags', function () {
    config()->set('filament-mobile.resources', [TaglessCardCompanyResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('TaglessCardCompanyResource: card field `tags` is bound to a tags path on a model without HasTags — the slot will always publish an empty list')
        ->assertExitCode(1);
});

it('names a spatie tags field whose name collides with a real column', function () {
    config()->set('filament-mobile.resources', [ColumnCollisionArticleResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('ColumnCollisionArticleResource.title: SpatieTagsInput collides with a real column of the same name — the tags value will shadow the column in the payload')
        ->assertExitCode(0);
});
