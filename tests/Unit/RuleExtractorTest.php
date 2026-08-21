<?php

declare(strict_types=1);

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Gait\MobileCore\WalkWarnings;

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

it('agrees with SchemaWalker on which NAMES a repeater contains, but not on their SHAPE — P6c\'s two name spaces', function () {
    // Repeater is supported (P6c Task 1) and, since Task 2, RuleExtractor
    // recurses into its item template like a container does — but prefixed
    // (`items.*.name`), never hoisted to a bare top-level `name` that could
    // collide with an unrelated field of the same key. The walker's `name`
    // is un-prefixed because it names ONE node in a tree the client walks
    // structurally; RuleExtractor's is prefixed because it names a flat
    // Laravel validation rule. Same field, two different name spaces by
    // design. See the design spec's "two different name spaces".
    $components = [
        Repeater::make('items')->required()->schema([
            TextInput::make('name')->required(),
        ]),
        TextInput::make('title')->required(),
    ];

    $walkerNames = collectWalkerFieldNames(
        (new SchemaWalker(new WalkWarnings()))->walk($components, 'test-resource'),
    );

    expect($walkerNames)->toEqualCanonicalizing(['items', 'name', 'title'])
        ->and(array_keys(RuleExtractor::fromComponents($components)))
        ->toEqualCanonicalizing(['items', 'items.*.name', 'title']);
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

it('admits a multiple-file field with container rules, declared count bounds and a per-element string rule', function () {
    // P12: a multiple field's value is a List<String> of stored paths, saved
    // wholesale like a repeater's — so its name enters the rules (and with
    // them the mass-assignment whitelist) with `array`/`list` on the
    // CONTAINER, the declared maxFiles/minFiles as COUNT rules (Laravel's
    // min/max on an array count its elements — Filament's own
    // getValidationRules() shapes a multiple field identically), and a
    // per-element `string` under `name.*` so a crafted `[1, 2]` 422s keyed
    // `attachments.0`. Per-FILE constraints (mimetypes, size) do not travel
    // as rules — the upload endpoint enforces those per file.
    $components = [
        Filament\Forms\Components\FileUpload::make('attachments')->multiple()->required()->maxFiles(3)->minFiles(1),
        TextInput::make('name')->required(),
    ];

    $walkerNames = collectWalkerFieldNames(
        (new SchemaWalker(new WalkWarnings()))->walk($components, 'test-resource'),
    );

    expect(RuleExtractor::fromComponents($components))->toBe([
        'attachments' => ['array', 'list', 'required', 'max:3', 'min:1'],
        'attachments.*' => ['string'],
        'name' => ['required'],
    ])->and($walkerNames)->toEqualCanonicalizing(['attachments', 'name']);
});

it('admits an undeclared multiple-file field with the container rules alone', function () {
    expect(RuleExtractor::fromComponents([
        Filament\Forms\Components\FileUpload::make('gallery')->multiple(),
    ]))->toBe([
        'gallery' => ['array', 'list'],
        'gallery.*' => ['string'],
    ]);
});

it('still withholds a file field whose multiple() gate throws', function () {
    // The throw-must-not-admit rule is unchanged by P12: isMultiple()
    // answering neither true nor false keeps the field out of the rules,
    // so out of the validated payload, so the column can be neither written
    // nor cleared — the same closed answer the walker (readOnly: true,
    // unwritable) and the upload resolver (403) give on this throw.
    expect(RuleExtractor::fromComponents([
        Filament\Forms\Components\FileUpload::make('boom')->multiple(fn () => throw new RuntimeException('broken gate')),
        TextInput::make('name'),
    ]))->toBe(['name' => ['nullable']]);
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
            // A leaf name in its own right, AND (since P6c Task 2) a
            // container whose item template contributes prefixed per-item
            // names — `items.*.leaked` never surfaces as a bare top-level
            // `leaked` that could collide with an unrelated field.
            Repeater::make('items')->schema([TextInput::make('leaked')]),
            // Multiple, deliberately: since P12 it is the two-name leaf —
            // the container AND its per-element `gallery.*` — so this pins
            // that the attribute keying follows even that starred name.
            FileUpload::make('gallery')->multiple()->required(),
        ]),
        TextInput::make('plain'),
    ];

    expect(array_keys(RuleExtractor::attributesFrom($components)))
        ->toBe(array_keys(RuleExtractor::fromComponents($components)))
        ->toBe(['vat_number', 'items', 'items.*.leaked', 'gallery', 'gallery.*', 'plain']);
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
