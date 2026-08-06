<?php

declare(strict_types=1);

use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

function buildPanel(): array
{
    return (new PanelSchemaBuilder(new ResourceRegistry()))->build(null);
}

it('emits the contract document version and panel block', function () {
    $document = buildPanel();

    expect($document['version'])->toBe(1)
        ->and($document['panel'])->toHaveKeys(['id', 'title']);
});

it('includes only resources that declare mobile()', function () {
    $keys = array_column(buildPanel()['resources'], 'key');

    expect($keys)->toContain('posts')
        ->and($keys)->toContain('banners')
        ->and($keys)->not->toContain('secrets');
});

it('emits labels, permissions, card, search and sorts per resource', function () {
    $banner = collect(buildPanel()['resources'])->firstWhere('key', 'banners');

    expect($banner['labels'])->toHaveKeys(['singular', 'plural'])
        ->and($banner['permissions'])->toHaveKeys(['viewAny', 'view', 'create', 'update', 'delete'])
        ->and($banner['card']['title']['field'])->toBe('name')
        ->and($banner['search']['enabled'])->toBeTrue()
        ->and($banner['sorts'])->not->toBeEmpty();
});

it('emits form and infolist walked from the resource itself', function () {
    $banner = collect(buildPanel()['resources'])->firstWhere('key', 'banners');

    expect($banner['form'])->not->toBeEmpty()
        ->and($banner['infolist'])->not->toBeEmpty();
});

it('never emits an _actions key — actions are P3', function () {
    $json = json_encode(buildPanel());

    expect($json)->not->toContain('_actions');
});

it('honours a Gate::before deny for a model with no policy', function () {
    // Banner has no policy at all. An app whose authorization is a before()
    // hook (filament-shield, a super-admin gate) must still hide it — mobile
    // is never looser than the web panel.
    // The parameter is nullable so the callback also runs for a guest — Gate
    // skips before-callbacks that cannot be called with a null user.
    Gate::before(fn (?Authenticatable $user) => false);

    expect(array_column(buildPanel()['resources'], 'key'))->not->toContain('banners');
});

it('reports resource permissions as capability, not a per-record answer', function () {
    // PostPolicy::update() denies everyone, but it is typed against a record,
    // so the resource-level block reports the capability. The per-record
    // answer is computed by the record endpoints against the real record.
    $posts = collect(buildPanel()['resources'])->firstWhere('key', 'posts');

    expect($posts['permissions']['update'])->toBeTrue()
        ->and($posts['permissions']['viewAny'])->toBeTrue();
});

it('omits a resource the user may not viewAny', function () {
    // The fixture PostPolicy denies viewAny for a user named "restricted".
    $restricted = makeUser('restricted');

    $keys = array_column(
        (new PanelSchemaBuilder(new ResourceRegistry()))->build($restricted)['resources'],
        'key',
    );

    expect($keys)->not->toContain('posts')
        ->and($keys)->toContain('banners');
});
