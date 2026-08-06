<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Gait\FilamentMobile\Tests\Fixtures\Resources\DriftResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SecretResource;

beforeEach(function () {
    // DriftResource is added here rather than to the shared fixture list so
    // that a resource built to be broken cannot skew the endpoint tests.
    config()->set('filament-mobile.resources', [
        PostResource::class,
        BannerResource::class,
        SecretResource::class,
        DriftResource::class,
    ]);
});

it('lists which resources are exposed and which are not', function () {
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('banners')
        ->expectsOutputToContain('SecretResource');
});

it('reports unsupported components with a reason', function () {
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('unsupported component');
});

it('reports a sort key that is not a column on the resource table', function () {
    // The fixture DriftResource declares sorts(['nonexistent' => '…']).
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('nonexistent')
        ->assertExitCode(1);
});

it('reports a card field whose relation does not exist on the model', function () {
    // The fixture DriftResource declares subtitle('ghost.name').
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('ghost');
});

it('reports an action name that resolves nowhere', function () {
    // BannerResource declares 'ghost' among actions(); no table action
    // answers to it. Assert the ActionResolver::problems() wording exactly
    // ("no such action") rather than bare 'ghost' — DriftResource's own
    // unresolvable card path (`ghost.name`) already puts that substring in
    // the output, which would make a bare-substring assertion pass before
    // this feature exists at all.
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('ghost: no such action')
        ->assertExitCode(1);
});

it('reports an opted-in action that carries a form', function () {
    // BannerResource's 'reject' table action declares a schema.
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('carries a form')
        ->assertExitCode(1);
});

it('exits zero when nothing actionable is found', function () {
    config()->set('filament-mobile.resources', [PostResource::class]);

    $this->artisan('filament-mobile:doctor')->assertExitCode(0);
});

it('does not call a sort on a database column the table does not display drift', function () {
    // PostResource sorts by `created_at`; its table() shows only `title`. That
    // is valid — the sort runs as `orderBy('created_at')` against the database
    // — and a gate that fails CI on it would be switched off within a week.
    config()->set('filament-mobile.resources', [PostResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('`created_at` is declared')
        ->assertExitCode(0);
});

it('inspects a policy-guarded panel when given an identity to build as', function () {
    // Without --user the run below is permanently red and blind: viewAny
    // denies the anonymous console user, every section reads `(none)` because
    // nothing was walked, and skipped() forces exit 1 forever. doctor is spec
    // §8's CI gate for the accepted mobile()/table() duplication, so a gate
    // that can never go green is a gate that gets deleted.
    config()->set('filament-mobile.resources', [PostResource::class]);
    $user = makeUser('admin');
    Gate::before(fn (?Authenticatable $u) => $u === null ? false : null);

    $this->artisan('filament-mobile:doctor', ['--user' => (string) $user->id])
        ->doesntExpectOutputToContain('Not inspected')
        ->assertExitCode(0);
});

it('resolves --user by email as well as by id', function () {
    config()->set('filament-mobile.resources', [PostResource::class]);
    makeUser('admin');
    Gate::before(fn (?Authenticatable $u) => $u === null ? false : null);

    $this->artisan('filament-mobile:doctor', ['--user' => 'admin@example.test'])
        ->assertExitCode(0);
});

it('fails without inspecting anything when --user names nobody', function () {
    $this->artisan('filament-mobile:doctor', ['--user' => 'ghost@example.test'])
        ->expectsOutputToContain('No user matches')
        ->assertExitCode(1);
});

it('refuses to report clean when policies hid every resource from the console', function () {
    // Nothing was walked, so nothing was inspected: a green CI run here would
    // certify a panel no one looked at.
    config()->set('filament-mobile.resources', [PostResource::class]);
    // The parameter must be nullable, or Laravel skips the callback for the
    // guest the console run is.
    Gate::before(fn (?Authenticatable $user) => false);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('Not inspected')
        ->assertExitCode(1);
});
