<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\CardlessCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GhostCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MisdeclaredCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;

beforeEach(function () {
    config()->set('filament-mobile.resources', [
        PostResource::class,
        NarrowedCompanyResource::class,
        CardlessCompanyResource::class,
        GhostCompanyResource::class,
        MisdeclaredCompanyResource::class,
    ]);
});

it('names a refused relation and why', function () {
    // One compound substring, not two chained expectsOutputToContain() calls:
    // Testbench mocks doWrite per line and lets exactly one expectation claim
    // each call, so two substrings that both live in doctor's single
    // `Resource.Manager: reason` line would only ever satisfy the first one
    // registered — see DoctorCommand::relationRefusals().
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('NarrowedBannersRelationManager: narrows its own query')
        ->assertExitCode(0);
});

it('names a relation no card can be derived for', function () {
    // The refusal the spec and README already promised and nothing
    // implemented: the drop was a bare `continue` in PanelSchemaBuilder,
    // which `doctor` never sees, so the relation vanished in silence.
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('CardlessBannersRelationManager: no card')
        ->assertExitCode(0);
});

it('names a relation whose declared card fills no slot', function () {
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain("relationCard('banners') fills no slot")
        ->assertExitCode(0);
});

it('names a relationCard() key that matches no relation', function () {
    // A typo'd key is accepted silently and the derived card used instead,
    // so the author's declaration was simply never read.
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain("relationCard('bannerz') matches no relation")
        ->assertExitCode(0);
});

it('names a relation whose relationship does not resolve on the model', function () {
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('GhostsRelationManager: relationship [ghosts] does not resolve')
        ->assertExitCode(0);
});
