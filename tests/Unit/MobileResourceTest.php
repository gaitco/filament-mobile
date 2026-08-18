<?php

declare(strict_types=1);

use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;

it('configures a card through the callable', function () {
    $resource = MobileResource::make()
        ->card(fn (MobileCard $card) => $card->title('name')->subtitle('email'));

    expect($resource->getCard()->toArray())->toBe([
        'title' => ['field' => 'name'],
        'subtitle' => ['field' => 'email'],
    ]);
});

it('configures a relation card through the closure and keys it by relation', function () {
    $resource = MobileResource::make()
        ->relationCard('contacts', fn (MobileCard $card) => $card->title('name'));

    expect($resource->getRelationCard('contacts')?->toArray())->toBe(['title' => ['field' => 'name']]);
});

it('has no relation card for a key nothing declared', function () {
    expect(MobileResource::make()->getRelationCard('contacts'))->toBeNull();
});

it('is valid with nothing declared', function () {
    $resource = MobileResource::make();

    expect($resource->getCard()->toArray())->toBe([])
        ->and($resource->getSearchable())->toBe([])
        ->and($resource->getSorts())->toBe([])
        ->and($resource->getDefaultSortKey())->toBeNull()
        ->and($resource->getDefaultSortDirection())->toBe('asc');
});

it('emits the contract sorts shape with the default flagged', function () {
    $resource = MobileResource::make()
        ->sorts(['created_at' => 'الأحدث', 'name' => 'الاسم'])
        ->defaultSort('created_at', 'desc');

    expect($resource->sortsToArray())->toBe([
        ['key' => 'created_at', 'label' => 'الأحدث', 'direction' => 'desc', 'default' => true],
        ['key' => 'name', 'label' => 'الاسم', 'direction' => 'asc'],
    ]);
});

it('emits no default flag when defaultSort was never called', function () {
    $resource = MobileResource::make()->sorts(['name' => 'الاسم']);

    expect($resource->sortsToArray())->toBe([
        ['key' => 'name', 'label' => 'الاسم', 'direction' => 'asc'],
    ]);
});

it('rejects a defaultSort key that is not a declared sort', function () {
    expect(fn () => MobileResource::make()
        ->sorts(['name' => 'الاسم'])
        ->defaultSort('created_at'))
        ->toThrow(InvalidArgumentException::class);
});

it('normalises the default sort direction to lower case', function () {
    // `DESC` reached the wire verbatim. Laravel coped — applySort() lowercases
    // — so only the Dart parser broke, and it throws on the *whole* document:
    // every screen dead from a capitalisation.
    $resource = MobileResource::make()
        ->sorts(['created_at' => 'Newest'])
        ->defaultSort('created_at', 'DESC');

    expect($resource->getDefaultSortDirection())->toBe('desc')
        ->and($resource->sortsToArray()[0]['direction'])->toBe('desc');
});

it('rejects a default sort direction that is neither asc nor desc', function () {
    expect(fn () => MobileResource::make()
        ->sorts(['created_at' => 'Newest'])
        ->defaultSort('created_at', 'descending'))
        ->toThrow(InvalidArgumentException::class);
});

it('drops a default the later sorts() no longer declares', function () {
    // Otherwise sortsToArray() emits no `default` at all — a list that
    // silently loses its ordering, with nothing to read that says so.
    $resource = MobileResource::make()
        ->sorts(['created_at' => 'Newest'])
        ->defaultSort('created_at', 'desc')
        ->sorts(['name' => 'Name']);

    expect($resource->getDefaultSortKey())->toBeNull()
        ->and($resource->getDefaultSortDirection())->toBe('asc')
        ->and($resource->sortsToArray())->toBe([
            ['key' => 'name', 'label' => 'Name', 'direction' => 'asc'],
        ]);
});

it('keeps the default when a later sorts() still declares its key', function () {
    $resource = MobileResource::make()
        ->sorts(['created_at' => 'Newest'])
        ->defaultSort('created_at', 'desc')
        ->sorts(['created_at' => 'Latest', 'name' => 'Name']);

    expect($resource->getDefaultSortKey())->toBe('created_at')
        ->and($resource->getDefaultSortDirection())->toBe('desc');
});

it('records searchable columns in declaration order', function () {
    expect(MobileResource::make()->searchable(['name', 'slug'])->getSearchable())
        ->toBe(['name', 'slug']);
});

it('stores searchable columns per relation, independently of each other and the resource', function () {
    $resource = MobileResource::make()
        ->searchable(['title'])
        ->relationSearchable('tags', ['name', 'slug'])
        ->relationSearchable('banners', ['name']);

    expect($resource->getRelationSearchable('tags'))->toBe(['name', 'slug'])
        ->and($resource->getRelationSearchable('banners'))->toBe(['name'])
        ->and($resource->getRelationSearchable('comments'))->toBe([])
        ->and($resource->getSearchable())->toBe(['title']);
});

it('emits the contract sorts shape per relation with the default flagged', function () {
    $resource = MobileResource::make()
        ->relationSorts('tags', ['name' => 'Name', 'created_at' => 'Created'])
        ->relationDefaultSort('tags', 'name');

    expect($resource->relationSortsToArray('tags'))->toBe([
        ['key' => 'name', 'label' => 'Name', 'direction' => 'asc', 'default' => true],
        ['key' => 'created_at', 'label' => 'Created', 'direction' => 'asc'],
    ]);
});

it('emits empty sorts for a relation nothing declared', function () {
    expect(MobileResource::make()->relationSortsToArray('tags'))->toBe([]);
});

it('keeps relation sorts independent of the resource-level sorts', function () {
    $resource = MobileResource::make()
        ->sorts(['name' => 'Name'])
        ->defaultSort('name')
        ->relationSorts('tags', ['weight' => 'Weight']);

    expect($resource->relationSortsToArray('tags'))->toBe([
        ['key' => 'weight', 'label' => 'Weight', 'direction' => 'asc'],
    ])->and($resource->sortsToArray())->toBe([
        ['key' => 'name', 'label' => 'Name', 'direction' => 'asc', 'default' => true],
    ]);
});

it('rejects a relation default sort key the relation does not declare', function () {
    // Declared on the RESOURCE and on ANOTHER relation, still undeclared on
    // this one — the refusal is keyed by relation, or a typo'd relation name
    // would silently borrow the resource's sorts.
    expect(fn () => MobileResource::make()
        ->sorts(['name' => 'Name'])
        ->relationSorts('banners', ['name' => 'Name'])
        ->relationSorts('tags', ['weight' => 'Weight'])
        ->relationDefaultSort('tags', 'name'))
        ->toThrow(InvalidArgumentException::class);
});

it('normalises a relation default sort direction to lower case and rejects anything else', function () {
    $resource = MobileResource::make()
        ->relationSorts('tags', ['name' => 'Name'])
        ->relationDefaultSort('tags', 'name', 'DESC');

    expect($resource->getRelationDefaultSortDirection('tags'))->toBe('desc')
        ->and($resource->relationSortsToArray('tags')[0]['direction'])->toBe('desc');

    expect(fn () => MobileResource::make()
        ->relationSorts('tags', ['name' => 'Name'])
        ->relationDefaultSort('tags', 'name', 'descending'))
        ->toThrow(InvalidArgumentException::class);
});

it('drops a relation default a later relationSorts() no longer declares', function () {
    // Same dangling-default ruling as sorts() after defaultSort(): the wire
    // would emit no `default` flag while the endpoint still ordered by the
    // vanished key.
    $resource = MobileResource::make()
        ->relationSorts('tags', ['name' => 'Name'])
        ->relationDefaultSort('tags', 'name', 'desc')
        ->relationSorts('tags', ['weight' => 'Weight']);

    expect($resource->getRelationDefaultSortKey('tags'))->toBeNull()
        ->and($resource->getRelationDefaultSortDirection('tags'))->toBe('asc')
        ->and($resource->relationSortsToArray('tags'))->toBe([
            ['key' => 'weight', 'label' => 'Weight', 'direction' => 'asc'],
        ]);
});

it('answers the undeclared defaults for a relation nothing declared', function () {
    $resource = MobileResource::make();

    expect($resource->getRelationSearchable('tags'))->toBe([])
        ->and($resource->getRelationSorts('tags'))->toBe([])
        ->and($resource->getRelationDefaultSortKey('tags'))->toBeNull()
        ->and($resource->getRelationDefaultSortDirection('tags'))->toBe('asc');
});

it('exposes the keys each relation declaration map was called with', function () {
    // What RelationDiscovery checks against the relations the resource
    // actually declares — the stray-key refusal relationCard() already has.
    $resource = MobileResource::make()
        ->relationSearchable('tags', ['name'])
        ->relationSorts('banners', ['name' => 'Name'])
        ->relationDefaultSort('banners', 'name');

    expect($resource->getRelationSearchableKeys())->toBe(['tags'])
        ->and($resource->getRelationSortsKeys())->toBe(['banners'])
        ->and($resource->getRelationDefaultSortKeys())->toBe(['banners']);
});

it('declares no actions by default', function () {
    expect(MobileResource::make()->getActions())->toBe([]);
});

it('keeps declared action names in declaration order, de-duplicated and re-indexed', function () {
    // Order is the publication order the client renders buttons in, so it is
    // part of the contract, not an accident of the array.
    $resource = MobileResource::make()->actions(['approve', 'archive', 'approve']);

    expect($resource->getActions())->toBe(['approve', 'archive']);
});
