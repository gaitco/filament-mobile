<?php

declare(strict_types=1);

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Illuminate\Support\Arr;
use Gait\FilamentMobile\Introspection\ChildComponents;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Gait\FilamentMobile\Write\SettledSchema;
use Gait\FilamentMobile\Write\WritableNames;

/**
 * P6c Task 2: a repeater is a leaf that also has children. RuleExtractor
 * must publish per-item rules (`items.*.field`) so the server enforces what
 * a row must contain, while WritableNames must publish only the whole-array
 * name (`items`) — never a starred path — because SettledSchema's
 * Arr::has()/Arr::set() have no wildcard support (see the design spec's
 * "two different name spaces" and the verified facts in the task brief).
 */
function lineItemsRepeater(): Repeater
{
    return Repeater::make('line_items')
        ->schema([
            TextInput::make('sku')->required()->maxLength(20),
            TextInput::make('qty')->numeric(),
        ])
        ->minItems(1)
        ->maxItems(5);
}

it('publishes the repeater\'s own rule AND its per-item rules', function () {
    // Assertion 1.
    $rules = RuleExtractor::fromComponents([lineItemsRepeater()]);

    expect($rules)->toHaveKey('line_items')
        ->and($rules['line_items.*.sku'])->toContain('required')
        ->and($rules['line_items.*.sku'])->toContain('max:20')
        ->and($rules['line_items.*.qty'])->toContain('numeric');
});

it('derives the repeater\'s own array/min/max rules from minItems/maxItems', function () {
    // Assertion 2 — the server must enforce what the config publishes.
    $rules = RuleExtractor::fromComponents([lineItemsRepeater()]);

    expect($rules['line_items'])->toContain('array')
        ->and($rules['line_items'])->toContain('min:1')
        ->and($rules['line_items'])->toContain('max:5');
});

it('degrades to no min/max rule when minItems()/maxItems() throw, rather than 500ing the whole rule set', function () {
    $rules = RuleExtractor::fromComponents([
        Repeater::make('line_items')
            ->schema([TextInput::make('sku')])
            ->minItems(fn () => throw new RuntimeException('boom'))
            ->maxItems(fn () => throw new RuntimeException('boom')),
    ]);

    expect($rules['line_items'])->toBe(['array', 'list']);
});

it('names the repeater as ONE writable name and NEVER a starred path', function () {
    // Assertion 3 — asserted on the absence explicitly, not by counting.
    $names = WritableNames::of([lineItemsRepeater()]);

    expect($names)->toContain('line_items');

    foreach ($names as $name) {
        expect($name)->not->toContain('*');
    }
});

it('lets a disabled repeater contribute no rule and no writable name', function () {
    // Assertion 4.
    $components = [lineItemsRepeater()->disabled()];

    expect(RuleExtractor::fromComponents($components))->toBe([])
        ->and(WritableNames::of($components))->toBe([]);
});

it('lets a repeater inside a disabled section contribute no rule and no writable name', function () {
    // Assertion 5.
    $components = [
        Section::make('Restricted')->disabled()->schema([lineItemsRepeater()]),
    ];

    expect(RuleExtractor::fromComponents($components))->toBe([])
        ->and(WritableNames::of($components))->toBe([]);
});

it('names a relationship repeater as ONE writable name with NO rule — the relation pass, never a column, saves it (P9)', function () {
    // Assertion 6, inverted by P9. Before, `Repeater::relationship()`'s
    // literal `dehydrated(false)` was all that kept the field out of the
    // write path, so it contributed nothing at all. Since the relation pass
    // saves its rows through Filament's own saveRelationships(), it now
    // contributes exactly what a multi-valued relationship select does: its
    // whole-array name in WritableNames (so the settle keeps the submitted
    // rows for the relation pass), its component in relationWriteComponents,
    // and NO rule — a rule would put the name into the validated payload,
    // where update() would write it as a column that does not exist.
    // Wired into a real schema, because the classification asks
    // getRelationship() — which only a schema-mounted component can answer
    // (an unwired one throws, and the write path fails closed on that, as
    // the pre-P9 behaviour also did). Same idiom RepeaterSchemaTest uses.
    $components = Schema::make(null)
        ->model(Banner::class)
        ->components([
            Repeater::make('tag_rows')->relationship('tags')->schema([TextInput::make('name')]),
        ])
        ->getComponents();

    expect(WritableNames::of($components))->toBe(['tag_rows'])
        ->and(RuleExtractor::fromComponents($components))->toBe([])
        ->and(RuleExtractor::relationWriteComponents($components))->toHaveKey('tag_rows');
});

it('never lets a relation-write field nested inside a repeater carry a starred name into WritableNames', function () {
    // The precise regression this task's own review caught: a
    // CheckboxList::relationship() nested in a repeater's item schema is a
    // leaf whose OWN savesViaRelationship() answers true — the same
    // predicate relationWriteComponents() filters on for a TOP-LEVEL
    // relation field. Nested here, its entry is minted by the repeater
    // branch with a STARRED name (`line_items.*.tags`) and `writable:
    // false`, same as any other per-item entry. Without gating
    // relationWriteComponents() on `writable` too, that starred name still
    // passed the relation filter and reached WritableNames::of() — and
    // Arr::has()/Arr::set() treat `*` as an ordinary path segment, so a
    // payload shaped `{"line_items":{"*":{"tags":[...]}}}` would match,
    // settle, and reach a real saveRelationships() call for a name no
    // schema ever named at top level.
    $components = [
        Repeater::make('line_items')->schema([
            TextInput::make('sku')->required(),
            CheckboxList::make('tags')->relationship('tags', 'name'),
        ]),
    ];

    $names = WritableNames::of($components);

    expect($names)->toBe(['line_items']);

    foreach ($names as $name) {
        expect($name)->not->toContain('*');
    }
});

it('proves the settle is load-bearing: line_items rows survive, and a starred allow-set name would silently drop them', function () {
    $components = [lineItemsRepeater()];
    $submitted = ['line_items' => [
        ['sku' => 'ABC', 'qty' => '2'],
        ['sku' => 'XYZ', 'qty' => '5'],
    ]];

    // The real path: WritableNames::of() names only `line_items`, so the
    // whole submitted array survives the settle as one unit.
    $settled = SettledSchema::settle(
        submitted: $submitted,
        trusted: [],
        build: fn (array $state): array => [
            'components' => $components,
            'writable' => WritableNames::of($components),
        ],
    );

    expect($settled->state()['line_items'] ?? null)->toBe($submitted['line_items']);

    // Verified empirically (see task brief): Arr::has() has no wildcard
    // support, so it never matches a starred path against a nested
    // submitted array — reset() only ever calls Arr::set() when Arr::has()
    // already matched, so a starred name in the allow-set does not corrupt
    // state with a literal `*` key, it silently DROPS the row instead. That
    // is the exact failure the wildcard finding predicts, reproduced here
    // by forcing the broken allow-set the current implementation never
    // produces.
    expect(Arr::has($submitted, 'line_items.*.sku'))->toBeFalse();

    $broken = SettledSchema::settle(
        submitted: $submitted,
        trusted: [],
        build: fn (array $state): array => [
            'components' => $components,
            'writable' => ['line_items.*.sku', 'line_items.*.qty'],
        ],
    );

    expect($broken->state())->not->toHaveKey('line_items');
});

/**
 * P6c close-out, Finding 1. Inside a repeater, withholding a child's rule
 * does not withhold the CHILD — the whole array is one attribute, so
 * Laravel's `validated()` rebuilds it from the paths the rules name and the
 * unruled child's key is deleted from every row that gets written. At top
 * level the same mechanism protects the column; here it destroys it.
 *
 * There is no row identity on the wire to merge the stored value back by, so
 * the refusal is the whole field: no rule, no writable name, and the walker
 * publishes `config.readOnly: true` off the same predicate.
 */
it('refuses a whole repeater when a child of its item template would not round-trip', function () {
    $cases = [
        'disabled' => TextInput::make('rate')->disabled(),
        'hidden component' => Hidden::make('id'),
        'never dehydrated' => TextInput::make('note')->dehydrated(false),
        // P8 Task 3 mapped ColorPicker (formerly this fixture's stand-in) to
        // `color`, so an actually-unmapped component is needed here now:
        // MarkdownEditor extends Field directly, not RichEditor — matching
        // is by exact class name (ComponentTypeMap's docblock), so it stays
        // unmapped even though RichEditor is.
        'unmapped type' => MarkdownEditor::make('notes'),
        // A multiple file CHILD is admitted since P12 (its per-element rule
        // prefixes like any other's) — the refusing file shape is now only
        // the multiplicity gate that cannot answer.
        'multiple file gate throws' => FileUpload::make('gallery')->multiple(fn () => throw new RuntimeException('broken gate')),
    ];

    foreach ($cases as $label => $child) {
        $components = [
            Repeater::make('line_items')->schema([
                TextInput::make('sku')->required(),
                $child,
            ]),
        ];

        expect(RuleExtractor::fromComponents($components))->toBe([], $label)
            ->and(WritableNames::of($components))->toBe([], $label);
    }
});

it('still names the offending child, so doctor can say which one cost the control', function () {
    $withheld = RuleExtractor::withheldChild([
        TextInput::make('sku'),
        Hidden::make('id'),
    ]);

    expect($withheld)->toBe('id');
});

it('admits a multiple-file child, prefixing its container and per-element rules into the row', function () {
    // P12: a multiple file field round-trips like any other admitted child —
    // `line_items.*.gallery` carries the container rules and
    // `line_items.*.gallery.*` the per-element `string`, off the same
    // descent as the top-level case, so a repeater holding one is no longer
    // refused whole.
    $components = [
        Repeater::make('line_items')->schema([
            TextInput::make('sku')->required(),
            FileUpload::make('gallery')->multiple()->maxFiles(2),
        ]),
    ];

    $rules = RuleExtractor::fromComponents($components);

    expect($rules['line_items'])->toBe(['array', 'list'])
        ->and($rules['line_items.*.sku'])->toBe(['required'])
        ->and($rules['line_items.*.gallery'])->toBe(['array', 'list', 'max:2'])
        ->and($rules['line_items.*.gallery.*'])->toBe(['string'])
        ->and(WritableNames::of($components))->toBe(['line_items']);
});

/**
 * The descent goes THROUGH a container and through a nested repeater: the
 * outer array is written whole, so a Hidden two levels down is stripped from
 * every inner row by the same rebuild, and the outer save is what writes it.
 */
it('finds a withheld child nested inside a container or an inner repeater', function () {
    expect(RuleExtractor::withheldChild([
        Section::make('Group')->schema([TextInput::make('sku'), Hidden::make('id')]),
    ]))->toBe('id');

    expect(RuleExtractor::withheldChild([
        Repeater::make('inner')->schema([TextInput::make('sku'), Hidden::make('id')]),
    ]))->toBe('id');
});

it('answers null for a template every child of which round-trips', function () {
    expect(RuleExtractor::withheldChild([
        TextInput::make('sku')->required(),
        Section::make('Group')->schema([TextInput::make('qty')->numeric()]),
    ]))->toBeNull();
});

/**
 * P6c close-out, Finding 5 — landed in P9. `Repeater::relationship()` calls
 * `dehydrated(false)` as a literal, and that literal was the ONLY thing
 * keeping a relationship repeater out of the write path —
 * `FieldPersistence::savesViaRelationship()` misclassified it as singular
 * (no `isMultiple()`), so `->dehydrated(true)` put its name in the
 * mass-assignment whitelist while `SchemaWalker` published the same node
 * `readOnly: true`. A crafted payload then reached `update()` as a column
 * that does not exist: `QueryException`, i.e. a 500 on crafted input.
 *
 * P9's deliberate `Repeater`-aware branch in `savesViaRelationship()` is the
 * fix the finding deferred: a relationship repeater is now a relation-write
 * leaf WHATEVER its dehydration says, so `dehydrated(true)` can no longer
 * route it into a column write — it gets no rule (never in the validated
 * payload) and its writable name belongs to the relation pass.
 */
it('never lets a relationship repeater become a column write, even with dehydrated(true)', function () {
    $components = Schema::make(null)
        ->model(Banner::class)
        ->components([
            Repeater::make('forced')
                ->relationship('tags')
                ->schema([TextInput::make('name')])
                ->dehydrated(true),
        ])
        ->getComponents();

    expect(RuleExtractor::fromComponents($components))->toBe([])
        ->and(WritableNames::of($components))->toBe(['forced']);
});

/**
 * P6c close-out, Finding 3. PHP's `array` admits a string-keyed map, and the
 * wildcard rules match a literal `*` key perfectly happily, so a crafted
 * `{"*": {...}}` validated cleanly and was stored verbatim.
 */
it('constrains a repeater to a LIST, not merely to an array', function () {
    $rules = RuleExtractor::fromComponents([lineItemsRepeater()]);

    expect($rules['line_items'])->toContain('list');

    expect(validator(['line_items' => ['*' => ['sku' => 'A']]], $rules)->fails())->toBeTrue();
    expect(validator(['line_items' => [['sku' => 'A']]], $rules)->fails())->toBeFalse();
});

/**
 * P6c close-out re-review. `withheldChild()` mirrors `childrenOf()`'s
 * refusals, but the RULES are `fromComponents()`, which applies one filter
 * `childrenOf()` does not: it drops every relation-write leaf. So a
 * `CheckboxList::relationship()` in an item template was MINTED by the
 * descent (the predicate saw nothing wrong) and then dropped from the rules —
 * and inside a repeater "no rule" does not withhold a key, it DELETES the key
 * from every row the save writes.
 *
 * Normally harmless, because `relationship()` sets `dehydrated(false)` as a
 * literal and Filament never puts the key in the row's state at all. The test
 * below pins exactly that. `->dehydrated(true)` overrides the literal, the key
 * IS stored, and the stored value was destroyed on every save behind a `200` —
 * see RepeaterWriteTest for the column-level assertion.
 */
it('refuses a repeater whose relation-write child is forced back into the row by dehydrated(true)', function () {
    $components = [
        Repeater::make('relation_rows')->schema([
            TextInput::make('title'),
            CheckboxList::make('tags')->relationship('tags', 'name')->dehydrated(true),
        ]),
    ];

    expect(RuleExtractor::withheldChild(ChildComponents::of($components[0])))->toBe('tags')
        ->and(RuleExtractor::fromComponents($components))->toBe([])
        ->and(WritableNames::of($components))->toBe([]);
});

/**
 * The load-bearing claim behind the exemption above, pinned rather than
 * argued: a PLAIN `relationship()` child is exempt ONLY because Filament
 * itself never dehydrates it into the row, so there is no stored key for the
 * missing rule to strip. The first assertion is that fact, read straight off
 * Filament; the second is the behaviour that rests on it. If a Filament
 * release stops setting the literal, the first assertion reds here rather
 * than the defect resurfacing as silent data loss in a panel.
 */
it('leaves a repeater editable for a plain relationship child, because Filament stores no key for it', function () {
    $child = CheckboxList::make('tags')->relationship('tags', 'name');

    expect($child->isDehydrated())->toBeFalse();

    $components = [
        Repeater::make('relation_rows')->schema([TextInput::make('title'), $child]),
    ];

    expect(RuleExtractor::withheldChild(ChildComponents::of($components[0])))->toBeNull()
        ->and(WritableNames::of($components))->toBe(['relation_rows']);
});

/**
 * The standing rule: a gate that cannot answer refuses. `read()` returns null
 * on a throw, and only an answer of literal `false` earns the exemption above.
 */
it('refuses a relation-write child whose dehydration gate cannot answer', function () {
    $components = [
        Repeater::make('relation_rows')->schema([
            TextInput::make('title'),
            CheckboxList::make('tags')
                ->relationship('tags', 'name')
                ->dehydrated(fn () => throw new RuntimeException('boom')),
        ]),
    ];

    expect(RuleExtractor::withheldChild(ChildComponents::of($components[0])))->toBe('tags')
        ->and(RuleExtractor::fromComponents($components))->toBe([]);
});

/**
 * The load-bearing claim behind `resource_form_provider.dart`'s three-segment
 * error-key rule: LAYOUT nesting inside an item template does not lengthen the
 * per-item path, only a nested REPEATER does. A `422` for a child inside a
 * `Section` inside a row is still keyed `<repeater>.<index>.<child>`, which is
 * the one shape `RepeaterFieldWidget` can render an error against.
 */
it('keys a per-item rule at exactly <repeater>.*.<child>, however deep the LAYOUT nesting', function () {
    $rules = RuleExtractor::fromComponents([
        Repeater::make('line_items')->schema([
            Section::make('Group')->schema([
                Section::make('Inner')->schema([TextInput::make('sku')->required()]),
            ]),
        ]),
    ]);

    expect($rules)->toHaveKey('line_items.*.sku');

    foreach (array_keys($rules) as $name) {
        expect(substr_count($name, '.'))->toBeLessThanOrEqual(2, $name);
    }
});
