<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\ComponentTypeMap;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;

// Second golden file, not a rewrite of contract/panel.json. That one is P0's
// hand-written example covering every v1 shape; this one is what Laravel
// actually emits from real resources. Regenerate with UPDATE_SNAPSHOTS=1.
const SNAPSHOT = __DIR__ . '/../../../../contract/laravel-panel.json';

function snapshotDocument(): array
{
    return (new PanelSchemaBuilder(new ResourceRegistry()))->build(null);
}

it('matches the committed contract snapshot', function () {
    $json = json_encode(
        snapshotDocument(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";

    if (getenv('UPDATE_SNAPSHOTS') === '1') {
        file_put_contents(SNAPSHOT, $json);
    }

    expect(SNAPSHOT)->toBeReadableFile()
        ->and($json)->toBe(file_get_contents(SNAPSHOT));
});

/**
 * Every component node, at every depth. Asserting only the top-level resource
 * keys would let a regression through: if the walker stopped emitting `rules`
 * on every field, a resource-granularity test stays green and someone reruns
 * with UPDATE_SNAPSHOTS=1 and commits the loss as a fixture update. Field
 * granularity is where the Dart parser actually reads.
 *
 * @param  list<array<string, mixed>>  $nodes
 * @return list<array<string, mixed>>
 */
function flattenNodes(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        $flat[] = $node;
        $flat = [...$flat, ...flattenNodes($node['children'] ?? [])];
    }

    return $flat;
}

it('emits every key the frozen Dart parser reads', function () {
    // `relations` is asserted at resource granularity below (P6d Task 5's
    // gap-close: a new wire key with no field-granularity coverage is
    // exactly the miss this test exists to catch), not flattened here.
    // flattenNodes() walks `children`, and a relation entry — `{key, label,
    // card}` — is not a component: it has none of a node's shape
    // (type/name/label/helperText/hidden/disabled/live/rules) and no
    // `children` to recurse into. Asserting the node-key list against a
    // relation would be a type mismatch, not coverage. The relation entry's
    // own shape is covered where it belongs: RelationSchemaTest.php here,
    // and laravel_contract_test.dart on the Dart side.
    $document = snapshotDocument();

    expect($document)->toHaveKeys(['version', 'panel', 'resources'])
        ->and($document['resources'])->not->toBeEmpty();

    $nodes = [];

    foreach ($document['resources'] as $resource) {
        expect($resource)->toHaveKeys([
            'key', 'labels', 'permissions', 'recordKey',
            'card', 'search', 'sorts', 'filters', 'relations', 'form', 'infolist',
        ]);

        $nodes = [
            ...$nodes,
            ...flattenNodes($resource['form']),
            ...flattenNodes($resource['infolist']),
        ];
    }

    expect($nodes)->not->toBeEmpty();

    foreach ($nodes as $node) {
        expect($node)->toHaveKeys([
            'type', 'name', 'label', 'helperText', 'hidden', 'disabled', 'live', 'rules',
        ]);
    }
});

it('exercises every component type the package can emit', function () {
    // A type absent from the fixtures is a type the Filament 4/5 CI matrix
    // proves nothing about. The expected set is DERIVED from ComponentTypeMap,
    // never restated: the hand-maintained `toContain` list this replaces named
    // 16 of the 20 emittable types and passed on a subset, so adding a type to
    // the map could not turn it red — which is the icon_entry failure mode in
    // miniature.
    //
    // Every type is exercised, so the assertion is equality, not coverage.
    // A class mapped by name for a plugin that need not be installed
    // (SpatieMediaLibraryImageEntry, SpatieMediaLibraryFileUpload) shares its
    // type string with the plain component next to it, so nothing here has to
    // instantiate one.
    $emitted = [];

    foreach (snapshotDocument()['resources'] as $resource) {
        foreach ([...flattenNodes($resource['form']), ...flattenNodes($resource['infolist'])] as $node) {
            $emitted[$node['type']] = true;
        }
    }

    expect(array_diff(ComponentTypeMap::types(), array_keys($emitted)))
        ->toBeEmpty('no fixture emits these types, so the CI matrix and the Dart golden never see them')
        ->and(array_diff(array_keys($emitted), ComponentTypeMap::types()))
        ->toBeEmpty('emitted by the walker but unknown to ComponentTypeMap::types()');
});

it('flattens a tab into a labelled section without losing its children', function () {
    // The regression this guards: unmapped, a Tab is dropped as unsupported,
    // so every tabbed form emits a childless `tabs` node. The pilot panel has
    // 45 Tab::make() calls, i.e. 45 empty forms and 45 warnings.
    $posts = collect(snapshotDocument()['resources'])->firstWhere('key', 'posts');
    $tabs = collect(flattenNodes($posts['form']))->firstWhere('type', 'tabs');

    expect($tabs['children'])->toHaveCount(1);

    $tab = $tabs['children'][0];

    expect($tab['type'])->toBe('section')
        ->and($tab['label'])->toBe('Details')
        ->and(collect(flattenNodes($tab['children']))->pluck('name'))->toContain('author');
});

it('never emits _actions — actions are P3', function () {
    expect(json_encode(snapshotDocument()))->not->toContain('_actions');
});
