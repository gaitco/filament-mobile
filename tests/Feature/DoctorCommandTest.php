<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Gait\FilamentMobile\Tests\Fixtures\Resources\DriftResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MultiFileResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RepeaterProblemResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RichResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SecretResource;
use Gait\FilamentMobile\Tests\Fixtures\Widgets\PlainTableWidget;

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

it('reports a configured widget class that does not exist', function () {
    // Isolated from the DriftResource/BannerResource findings the shared
    // beforeEach() config produces, so a genuine RED here cannot be masked
    // by an exit code that was already 1 for an unrelated reason.
    config()->set('filament-mobile.resources', [PostResource::class]);
    config()->set('filament-mobile.widgets', ['Gait\\FilamentMobile\\Tests\\Fixtures\\Widgets\\NoSuchWidget']);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('no such class')
        ->assertExitCode(1);
});

it('reports a non-string widget config entry instead of crashing the run', function () {
    // The controller silently skips it; doctor exists to make exactly this
    // kind of dead config loud — and a TypeError would kill the whole run.
    config()->set('filament-mobile.resources', [PostResource::class]);
    config()->set('filament-mobile.widgets', [123]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('not a class-string')
        ->assertExitCode(1);
});

it('reports a configured widget that is neither stats nor chart', function () {
    config()->set('filament-mobile.resources', [PostResource::class]);
    config()->set('filament-mobile.widgets', [PlainTableWidget::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('neither a stats nor a chart widget')
        ->assertExitCode(1);
});

it('no longer reports a FileUpload::multiple() field — supported since P12', function () {
    // BannerResource declares `gallery` and `attachments` as multiple file
    // fields, and both are fully served now: published editable, admitted to
    // the write path, accepted by the upload endpoint. A stale "unsupported
    // this slice" line would tell the panel author to remove a control that
    // works — the negative assertion is the point.
    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('FileUpload::multiple()');
});

it('stays green over a multi-file field alone — multi-file is supported, not just tolerated', function () {
    config()->set('filament-mobile.resources', [MultiFileResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('FileUpload::multiple()')
        ->assertExitCode(0);
});

it('no longer reports a relationship repeater — P9 writes its rows through the relation pass', function () {
    // P9 inverted this finding: `rel_rows` is SUPPORTED now (the relation
    // pass calls Filament's own Repeater::saveToRelationship()), so doctor
    // must say nothing about it. The negative assertion is the point — a
    // stale "unsupported this slice" line would tell the panel author to
    // remove a control that works.
    config()->set('filament-mobile.resources', [RepeaterProblemResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('RepeaterProblemResource.rel_rows')
        ->assertExitCode(0);
});

it('reports a repeater containing a live() field, informationally', function () {
    config()->set('filament-mobile.resources', [RepeaterProblemResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('RepeaterProblemResource.live_rows: contains a live() field')
        ->assertExitCode(0);
});

it('reports a nested repeater, informationally', function () {
    config()->set('filament-mobile.resources', [RepeaterProblemResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('RepeaterProblemResource.inner_rows: nested repeater')
        ->assertExitCode(0);
});

it('names the child that cost a repeater its editability, informationally', function () {
    // The finding that loses user data if it goes unreported: a `Hidden` in
    // a row template is dropped by ComponentTypeMap, so no rule names it and
    // the whole-array write deletes it from every row. Naming the CHILD is
    // the point — `readOnly: true` on the wire says the control is gone, and
    // nothing else says why.
    config()->set('filament-mobile.resources', [RepeaterProblemResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('RepeaterProblemResource.guarded_rows: child `id` would not round-trip')
        ->assertExitCode(0);
});

it('does not report the outer half of a nested repeater as nested itself', function () {
    // Only the INNER repeater is inside another repeater's item template;
    // the outer one is an ordinary top-level repeater and must not also be
    // flagged.
    config()->set('filament-mobile.resources', [RepeaterProblemResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('outer_rows: nested repeater');
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

it('names a card bound to a column only an infolist entry called prose on', function () {
    // A card slot over such a column renders raw markup on the list screen —
    // `->prose()` governs one infolist entry, so index() publishes no
    // `<path>.__rich` for it (RichPayloadTest pins that). The panel author
    // has no other way to learn why one card slot came out clean and the one
    // beside it did not.
    //
    // Informational, like the multi-file and repeater sections: the panel's
    // declaration is legal, this slice simply cannot honour it on a card.
    config()->set('filament-mobile.resources', [RichResource::class]);

    // Exit 1, but NOT because of this section: RichResource's `exploding_body`
    // entry has a throwing ->prose() gate, which the walker warns about, and
    // that single warning is the run's one actionable finding. Asserting the
    // count is what proves this section stayed informational — an
    // assertExitCode(0) here would be unprovable against this fixture, and
    // dropping the exit assertion entirely would prove nothing at all.
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('RichResource: card field `prose_note` is rich only because the infolist calls ->prose()')
        ->expectsOutputToContain('1 actionable finding(s).')
        ->assertExitCode(1);
});

it('does not name a card bound to a column the model itself declares rich', function () {
    // `body_html` is on the same card and IS published with a sibling, so a
    // check that flagged every rich card field would be noise a panel author
    // learns to ignore.
    config()->set('filament-mobile.resources', [RichResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('card field `body_html`');
});
