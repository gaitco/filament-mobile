<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardedTagsBannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardlessCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;
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

it('names a published relation whose writes are off because no resource serves the child', function () {
    // P9: the relation READS fine, so it is no refusal — but with no mobile
    // resource over Banner its write endpoints 404 and /schema withholds the
    // `resource` key, and this line is the only place that says why.
    // CompanyResource alone in the set is what makes the count zero.
    config()->set('filament-mobile.resources', [CompanyResource::class]);

    $this->artisan('filament-mobile:doctor')
        // One compound substring, not two chained calls — both fragments land
        // on doctor's single line, and Testbench lets exactly one expectation
        // claim each printed line (see DoctorCommand::relationRefusals()).
        ->expectsOutputToContain('CompanyResource.banners: rows are read-only — the child model Banner is not unambiguously one resource (no mobile resource serves it)')
        ->assertExitCode(0);
});

it('names a published relation whose writes are off because SEVERAL resources serve the child', function () {
    // The other zero-or-several half: two resources over Banner, and the
    // count is what distinguishes the message from the zero case above. No
    // exit-code assertion here: BannerResource's own doctor findings (its
    // `ghost`/`reject` actions) fail the run for reasons unrelated to this
    // section — the same fixture-isolation reason RepeaterProblemResource
    // exists — and this test's claim is the line, not the code.
    config()->set('filament-mobile.resources', [
        CompanyResource::class,
        BannerResource::class,
        CardedTagsBannerResource::class,
    ]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('2 mobile resources serve it');
});
