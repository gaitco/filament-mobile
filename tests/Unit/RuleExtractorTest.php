<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\WalkWarnings;
use Gait\FilamentMobile\Validation\RuleExtractor;

/**
 * Flattens every field name SchemaWalker's node tree contains, recursing
 * through `children` the same way RuleExtractor recurses through layout
 * containers — used to assert the two agree on what a schema contains.
 *
 * @param  list<array<string, mixed>>  $nodes
 * @return list<string>
 */
function collectWalkerFieldNames(array $nodes): array
{
    $names = [];

    foreach ($nodes as $node) {
        if ($node['name'] !== null) {
            $names[] = $node['name'];
        }

        if (isset($node['children'])) {
            $names = [...$names, ...collectWalkerFieldNames($node['children'])];
        }
    }

    return $names;
}

it('extracts required from a field', function () {
    $rules = RuleExtractor::fromComponents([
        TextInput::make('name')->required(),
    ]);

    expect($rules)->toBe(['name' => ['required']]);
});

it('marks a field with no constraints as nullable rather than omitting it', function () {
    // Omitting it entirely would let an unexpected key through unvalidated.
    $rules = RuleExtractor::fromComponents([TextInput::make('bio')]);

    expect($rules)->toBe(['bio' => ['nullable']]);
});

it('extracts length bounds', function () {
    $rules = RuleExtractor::fromComponents([
        TextInput::make('name')->required()->maxLength(255)->minLength(3),
    ]);

    expect($rules['name'])->toContain('required')
        ->and($rules['name'])->toContain('max:255')
        ->and($rules['name'])->toContain('min:3');
});

it('extracts email and numeric from the input type', function () {
    $rules = RuleExtractor::fromComponents([
        TextInput::make('email')->email(),
        TextInput::make('credit')->numeric(),
    ]);

    expect($rules['email'])->toContain('email')
        ->and($rules['credit'])->toContain('numeric');
});

it('recurses through layout containers', function () {
    $rules = RuleExtractor::fromComponents([
        Section::make()->schema([
            TextInput::make('name')->required(),
            Section::make()->schema([TextInput::make('deep')->required()]),
        ]),
    ]);

    expect($rules)->toHaveKey('name')
        ->and($rules)->toHaveKey('deep');
});

it('skips a component with no name, such as a layout', function () {
    $rules = RuleExtractor::fromComponents([Section::make()]);

    expect($rules)->toBe([]);
});

it('skips a Hidden field, which the walker also drops', function () {
    $rules = RuleExtractor::fromComponents([
        Filament\Forms\Components\Hidden::make('secret'),
        TextInput::make('name'),
    ]);

    expect($rules)->toBe(['name' => ['nullable']]);
});

it('never throws on a component whose accessors fail', function () {
    $broken = new class {
        public function getName(): string
        {
            throw new RuntimeException('needs a Livewire context');
        }
    };

    expect(RuleExtractor::fromComponents([$broken]))->toBe([]);
});

// --- Review round 2: recursion must match SchemaWalker exactly, or the two
// disagree on which field names exist for the same schema (design point). ---

it('agrees with SchemaWalker on which field names exist, even through an unsupported container', function () {
    // Repeater has no ComponentTypeMap entry: the walker drops it (and
    // everything inside it) with a warning rather than emitting a node.
    $components = [
        Repeater::make('items')->required()->schema([
            TextInput::make('name')->required(),
        ]),
        TextInput::make('title')->required(),
    ];

    $walkerNames = collectWalkerFieldNames(
        (new SchemaWalker(new WalkWarnings()))->walk($components, 'test-resource'),
    );

    expect(array_keys(RuleExtractor::fromComponents($components)))
        ->toEqualCanonicalizing($walkerNames);
});

it('drops an unsupported container and its descendants rather than leaking them as top-level fields', function () {
    $rules = RuleExtractor::fromComponents([
        Repeater::make('items')->required()->schema([
            TextInput::make('name')->required(),
        ]),
        TextInput::make('title')->required(),
    ]);

    // Neither the Repeater itself nor its inner `name` field is renderable
    // by the client, so neither gets a rule — and `name` can't collide with
    // an unrelated top-level field of the same key.
    expect($rules)->toBe(['title' => ['required']]);
});

it('extracts rules from a container whose children were assigned as a Schema instance', function () {
    // getDefaultChildComponents() is typed array|Schema; a Schema isn't
    // Traversable, so it must be unwrapped like SchemaWalker unwraps it.
    $rules = RuleExtractor::fromComponents([
        Section::make()->childComponents(
            Schema::make()->components([TextInput::make('name')->required()]),
        ),
    ]);

    expect($rules)->toBe(['name' => ['required']]);
});

it('never throws on a raw string mixed into a schema array', function () {
    // ->schema() legally accepts a bare string alongside components.
    $rules = RuleExtractor::fromComponents([
        Section::make()->schema(['some text', TextInput::make('name')->required()]),
    ]);

    expect($rules)->toBe(['name' => ['required']]);
});

it('never throws when a constraint accessor itself throws', function () {
    $rules = RuleExtractor::fromComponents([
        TextInput::make('name')->required(fn () => throw new RuntimeException('boom')),
    ]);

    expect($rules)->toBe(['name' => ['nullable']]);
});

it('skips constraint accessors a component type does not define, such as Toggle', function () {
    $rules = RuleExtractor::fromComponents([
        Toggle::make('flag')->required(),
    ]);

    expect($rules)->toBe(['flag' => ['required']]);
});

it('withholds a rule from a file field, which is the one name the walker emits and this does not', function () {
    // No rule means no key in the validated array, and the validated array is
    // the mass-assignment whitelist — so a `file` value can never be written.
    // Asserted alongside the walker so the exception stays a *known* one field
    // wide rather than quietly growing.
    $components = [
        Filament\Forms\Components\FileUpload::make('avatar'),
        TextInput::make('name')->required(),
    ];

    $walkerNames = collectWalkerFieldNames(
        (new SchemaWalker(new WalkWarnings()))->walk($components, 'test-resource'),
    );

    expect(RuleExtractor::fromComponents($components))->toBe(['name' => ['required']])
        ->and($walkerNames)->toEqualCanonicalizing(['avatar', 'name']);
});

it('keys its validation attributes exactly as it keys its rules', function () {
    // The two are read off one descent so they cannot drift, and this is what
    // pins that. A parallel walk would fail silently: an attribute keyed by a
    // name no rule mentions is simply never applied, and the 422 falls back to
    // the humanised column name — the defect the pilot measured on 131 of
    // 187 real constrained fields.
    $components = [
        Section::make('Details')->schema([
            TextInput::make('vat_number')->label('VAT Certificate')->required(),
            Repeater::make('items')->schema([TextInput::make('leaked')]),
            FileUpload::make('avatar')->required(),
        ]),
        TextInput::make('plain'),
    ];

    expect(array_keys(RuleExtractor::attributesFrom($components)))
        ->toBe(array_keys(RuleExtractor::fromComponents($components)))
        ->toBe(['vat_number', 'plain']);
});

it('reads the label into the attribute, not the column name', function () {
    // Filament's own rule closures pass getValidationAttribute(); Laravel's
    // fallback humanises the key. Where a field carries a label they differ,
    // and the 422 must follow the panel.
    expect(RuleExtractor::attributesFrom([
        TextInput::make('vat_number')->label('VAT Certificate')->required(),
    ]))->toBe(['vat_number' => 'vAT Certificate']);
});

it('leaves an unlabelled field reading exactly as Laravel would', function () {
    // The guard rail for passing an attribute for EVERY name: Filament's
    // default label humanises to Laravel's own :attribute fallback, so this
    // must be a no-op wherever no label was set.
    expect(RuleExtractor::attributesFrom([TextInput::make('bounded_quantity')]))
        ->toBe(['bounded_quantity' => 'bounded quantity']);
});
