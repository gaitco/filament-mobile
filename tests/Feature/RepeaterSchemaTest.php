<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RepeaterProblemResource;
use Gait\MobileCore\WalkWarnings;

/**
 * P6c Task 1: the walker publishes a repeater. Before this, `Repeater` was
 * unmapped in ComponentTypeMap, so the walker dropped it with a warning and
 * emitted no node — the field and all its data were invisible on mobile.
 */
it('publishes a repeater node with its item template and config', function () {
    $node = findFormNode(schemaFor('banners'), 'line_items');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('repeater')
        ->and($node['config']['addable'])->toBeTrue()
        ->and($node['config']['deletable'])->toBeTrue()
        ->and($node['config']['minItems'])->toBe(1)
        ->and($node['config']['maxItems'])->toBe(5);

    $children = collect($node['children'])->keyBy('name');

    expect($children)->toHaveKeys(['sku', 'qty'])
        ->and($children['sku']['type'])->toBe('text')
        ->and($children['sku']['rules']['required'])->toBeTrue()
        ->and($children['sku']['rules']['max'])->toBe(20)
        ->and($children['qty']['type'])->toBe('number')
        ->and($children['qty']['rules']['numeric'])->toBeTrue();
});

it('publishes addable and deletable as false when the field gates them off', function () {
    $node = findFormNode(schemaFor('banners'), 'fixed_rows');

    expect($node['config']['addable'])->toBeFalse()
        ->and($node['config']['deletable'])->toBeFalse();
});

/**
 * P6c Task 7. `readOnly` must be PRESENT and false, not merely falsy: the
 * client reads an absent key as read-only on purpose (an old server that
 * predates repeater support must not be guessed editable), so publishing
 * nothing at all rendered every ordinary repeater inert. Asserting
 * `toBeFalse()` alone would have stayed green through that whole bug —
 * `$config['readOnly'] ?? null` is falsy too.
 */
it('publishes an explicit readOnly: false for an ordinary repeater', function () {
    $node = findFormNode(schemaFor('banners'), 'line_items');

    expect($node['config'])->toHaveKey('readOnly')
        ->and($node['config']['readOnly'])->toBeFalse();
});

it('publishes a relationship repeater editable, writable through the relation pass (P9)', function () {
    // P9 inverted this node's answer. Before, a relationship repeater was
    // published `readOnly: true` (plus `disabled`/`writable: false` through
    // the singular misread in savesViaRelationship()) because the write path
    // never called saveRelationships(); since the relation pass does, the
    // field publishes exactly like an ordinary writable repeater — readOnly
    // present and false, no `writable` key, not disabled.
    $node = findFormNode(schemaFor('banners'), 'tag_rows');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('repeater')
        ->and($node['config'])->toHaveKey('readOnly')
        ->and($node['config']['readOnly'])->toBeFalse()
        ->and($node['disabled'])->toBeFalse()
        ->and($node)->not->toHaveKey('writable');
});

/**
 * The relationship gate is the one closure this field keys WRITABILITY off,
 * so it must fail closed like `file`'s accept/maxSize gates do. Read through
 * read(), it failed OPEN: read() returns its fallback (null) when the
 * accessor throws, and null reads as "not a relationship" — publishing an
 * editable control the write path can never save.
 */
it('publishes readOnly for a repeater whose relationship gate throws', function () {
    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk();

    $resource = collect($response->json('resources'))->firstWhere('key', 'banners');
    $node = findFormNodeWhere($resource, fn (array $n): bool => ($n['name'] ?? null) === 'exploding_relationship');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('repeater')
        ->and($node['config']['readOnly'])->toBeTrue();

    expect(collect($response->json('_warnings'))->where('component', 'exploding_relationship'))
        ->not->toBeEmpty();
});

it('degrades a repeater with a throwing config closure to a node, with a warning, rather than dropping it', function () {
    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk();

    $resource = collect($response->json('resources'))->firstWhere('key', 'banners');
    $node = findFormNodeWhere($resource, fn (array $n): bool => ($n['name'] ?? null) === 'exploding_repeater');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('repeater');

    $warnings = collect($response->json('_warnings'))
        ->where('component', 'exploding_repeater');

    expect($warnings)->not->toBeEmpty();
});

it('publishes the item template once, not once per stored row', function () {
    seedBannerWith(['line_items' => [
        ['sku' => 'a', 'qty' => 1],
        ['sku' => 'b', 'qty' => 2],
        ['sku' => 'c', 'qty' => 3],
    ]]);
    seedBannerWith(['line_items' => [
        ['sku' => 'd', 'qty' => 4],
    ]]);

    $node = findFormNode(schemaFor('banners'), 'line_items');

    expect($node['children'])->toHaveCount(2);
});

/**
 * Repeater::setUp() unconditionally calls defaultItems(1), whose default()
 * override keys its one blank item under a freshly generated random UUID on
 * EVERY evaluation. SchemaWalker withholds it from `/schema` for that reason
 * (see SchemaWalker::node()), but FormDefaults::forComponent() calls the same
 * getDefaultState() and feeds its answer straight into $model::create() via
 * MobilePanelController::fillMissingPaths() — Banner::$guarded = [] does
 * nothing to stop it. Column-level assertion, deliberately: asserting only
 * the 201 (as the pre-existing "creates a record" test in WriteEndpointTest
 * does) is exactly the blind spot that let `{"<uuid>": []}` — a dict, not the
 * list-of-maps shape the design spec documents — reach the JSON column on
 * every ordinary create that never mentions line_items at all.
 */
it('leaves a repeater column untouched on create when the client never mentions it', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', ['name' => 'No items', 'body_html' => '<p>Body</p>'])
        ->assertCreated();

    $banner = Banner::query()->where('name', 'No items')->firstOrFail();

    expect($banner->line_items)->toBeNull();
});

/**
 * P6c close-out, Finding 1. A child whose own rule would be withheld does
 * not protect that child inside a repeater the way it does at top level — it
 * DELETES the child's key from every row that gets written, because the
 * whole array is one attribute and `validated()` rebuilds it from the paths
 * the rules name. With no row identity on the wire there is nothing to merge
 * the stored value back by (an index-merge pairs row 2's id with row 3's
 * data the moment a row is added), so the field fails closed: read-only on
 * the wire, and no rule at all on the write path.
 */
it('publishes a repeater read-only when a child of its item template would not round-trip', function () {
    $node = findFormNode(schemaFor('banners'), 'guarded_rows');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('repeater')
        ->and($node['config']['readOnly'])->toBeTrue();
});

/**
 * P6c close-out, Finding 2. Three documents — the spec's Known weaknesses,
 * both READMEs, and `RepeaterFieldWidget`'s own docblock — asserted that the
 * walker publishes a nested repeater `readOnly: true`. Nothing did: it
 * shipped editable, with working Add/Remove, and a nested row's server 422
 * arrives under a key (`outer.0.inner.1.x`) the client has no field to
 * render it against — a form that cannot be submitted and cannot say why.
 */
it('publishes a repeater nested inside another repeater read-only', function () {
    $walker = new SchemaWalker(new WalkWarnings());
    $nodes = $walker->walk(
        RepeaterProblemResource::form(Schema::make(null)->model(Banner::class))->getComponents(),
        'RepeaterProblemResource',
    );

    $outer = collect($nodes)->firstWhere('name', 'outer_rows');
    $inner = collect($outer['children'])->firstWhere('name', 'inner_rows');

    expect($outer['config']['readOnly'])->toBeFalse()
        ->and($inner['config']['readOnly'])->toBeTrue();
});

/**
 * P6c close-out re-review. The published half of the refusal above: a
 * relation-write child that `->dehydrated(true)` forces back into the row's
 * stored state gets no rule, so the whole field is refused — and the client
 * must be told, or it renders an editable control whose every save eats a
 * stored key.
 */
it('publishes a repeater read-only when a relation-write child is forced back into the row', function () {
    $node = findFormNode(schemaFor('banners'), 'relation_rows');

    expect($node)->not->toBeNull()
        ->and($node['config']['readOnly'])->toBeTrue();
});
