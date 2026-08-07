<?php

declare(strict_types=1);

use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Components\Section;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Gait\FilamentMobile\Write\WritableNames;
use Illuminate\Support\Arr;

/**
 * P7 Task 2: `TagsInput` becomes a mapped, writable type.
 *
 * The part with teeth is not the config: `TagsInput` implements
 * `HasNestedRecursiveValidationRules` and this package had never handled that
 * interface — `grep -rn NestedRecursive src/` returned nothing before this
 * task. So `->nestedRecursiveRules(['max:20'])` was enforced by the web panel
 * and silently unenforced by the mobile API, which is precisely the "mobile
 * must never be looser than web" violation `Authorizer` states and the
 * README sells.
 *
 * The real accessor, read from vendor rather than guessed (wrong-name bugs
 * have shipped twice in this project — P6f's `filament::` prefix and P7's own
 * `canEditKeys`, both failing silently in the safe-looking direction), is
 * `getNestedRecursiveValidationRules()`. See
 * vendor/filament/forms/src/Components/Contracts/HasNestedRecursiveValidationRules.php.
 */
function tagsNode(string $name): array
{
    return findFormNode(schemaFor('banners'), $name);
}

/** A tags field carrying real per-tag rules, for the two name-space tests. */
function labelsTagsInput(): TagsInput
{
    return TagsInput::make('labels')
        ->suggestions(['urgent', 'billing'])
        ->nestedRecursiveRules(['max:20']);
}

it('publishes a tags field with its separator and suggestions', function () {
    expect(tagsNode('labels')['type'])->toBe('tags')
        // Never configured reads as null — the value on the wire is a
        // List<String> either way, so the client has one shape to render and
        // the join (Task 3) stays server-side.
        ->and(tagsNode('labels')['config']['separator'])->toBeNull()
        ->and(tagsNode('labels')['config']['suggestions'])->toBe(['urgent', 'billing'])
        ->and(tagsNode('separated_labels')['config']['separator'])->toBe(',')
        ->and(tagsNode('separated_labels')['config']['suggestions'])->toBe([]);
});

it('degrades a throwing separator closure to no separator, not a failed document', function () {
    // Every config value goes through the walker's guarded reader: one
    // field's closure throwing costs that field's separator, never the
    // whole /schema document.
    $node = tagsNode('exploding_labels');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('tags')
        ->and($node['config']['separator'])->toBeNull();
});

it('extracts per-tag rules as a starred path', function () {
    $rules = RuleExtractor::fromComponents([labelsTagsInput()]);

    expect($rules)->toHaveKey('labels')
        ->and($rules)->toHaveKey('labels.*')
        // Not merely present — the actual nested rule, so a test that
        // asserted the key while the value came back as the component's OWN
        // rules (the shape a naive `rulesFor($component)` produces for both
        // entries) cannot pass.
        // `string` first, then the panel's own — the seed composes with the
        // declared rules rather than clobbering or duplicating them. Both
        // apply: a 21-character tag still 422s on `max`, a nested array
        // 422s on `string`. See the final-review Finding 1 tests in
        // TagsWriteTest.
        ->and($rules['labels.*'])->toBe(['string', 'max:20'])
        // The whole-array name is constrained to an ARRAY, which is what
        // makes the per-tag rules reachable at all: a submitted
        // `"a,b"` string has no `labels.*` elements to check.
        ->and($rules['labels'])->toContain('array');
});

it('still emits a string-seeded starred path for a tags field with no nested rules', function () {
    // Changed by the P7 final review, Finding 1. The starred name used to be
    // minted only when the panel had declared nested rules — so a plain tags
    // field constrained its CONTAINER (`array`, `list`) and nothing at all
    // about its ELEMENTS, which is how `{"separated_labels": [["x"], "y"]}`
    // reached `implode()` and answered 500.
    //
    // `string` is not a panel preference, it is the published contract: a
    // `tags` value is a `List<String>` in every case. So every tags field
    // now has something to enforce through the starred name.
    $rules = RuleExtractor::fromComponents([TagsInput::make('plain_tags')]);

    expect($rules)->toHaveKey('plain_tags')
        ->and($rules['plain_tags.*'])->toBe(['string'])
        // The name-space split is unaffected — verified, not assumed. The
        // seeded entry is minted `writable: false` exactly like the declared
        // one, so it never reaches the settle's allow-set.
        ->and(WritableNames::of([TagsInput::make('plain_tags')]))->toBe(['plain_tags']);
});

it('leaves an ordinary field with no nested rules unstarred', function () {
    // The seed is scoped to `tags`, not to every leaf: a `TextInput` has no
    // elements, so a starred name for it would be a name the settle has to
    // keep excluding for no gain.
    expect(RuleExtractor::fromComponents([\Filament\Forms\Components\TextInput::make('name')]))
        ->not->toHaveKey('name.*');
});

it('keeps the starred path out of the settle allow-set', function () {
    // Both halves are asserted, because asserting only the first would pass
    // against the bug.
    $names = WritableNames::of([labelsTagsInput()]);

    expect($names)->toContain('labels')
        ->and($names)->not->toContain('labels.*');

    foreach ($names as $name) {
        expect($name)->not->toContain('*');
    }
});

it('pins the premise the split rests on: Arr::has cannot express a starred name', function () {
    // Measured here rather than inherited. `SettledSchema::reset()` is
    // `if (Arr::has($submitted, $path)) { Arr::set($state, $path, …); }`, and
    // `Arr::has()` walks `labels` then `*` — `array_key_exists('*', [...])`
    // is false — so a starred name in the allow-set never matches.
    //
    // What that means for THIS shape is milder than P6c's repeater finding,
    // and the difference matters: `labels.*`'s parent `labels` is itself
    // writable and always minted alongside, so a starred name here would be
    // INERT, not destructive. P6c measured `items.*.child`, whose `child` is
    // not separately a top-level writable name — there, nothing else covers
    // the write, so the drop is real. The split stays mandatory either way
    // (a name the settle cannot express has no business in a set whose only
    // job is expressing names), but the reason is "meaningless", not "eats
    // the value".
    //
    // Runnable rather than asserted in prose: if a future Laravel makes
    // `Arr::has()` wildcard-aware, this reds — which is exactly when every
    // comment resting on it stops being true.
    expect(Arr::has(['labels' => ['urgent', 'billing', 'vip']], 'labels.*'))->toBeFalse();
});

it('refuses a tags field whose nested-rule closure cannot answer', function () {
    // The one degradation on this path whose direction is MOBILE LOOSER THAN
    // WEB. `nestedRecursiveRules()` takes a closure condition that
    // `getNestedRecursiveValidationRules()` evaluates, so a throwing gate is
    // reachable — and read through the ordinary catch-and-degrade reader it
    // answered "no nested rules", minting no starred name and publishing an
    // UNBOUNDED tags field. Every other guarded read in this package can
    // degrade safely because the worst case is a missing hint; here the worst
    // case is a dropped constraint, which is the exact violation this task
    // exists to close.
    //
    // So it refuses the whole field instead — the same closed answer
    // `FileUpload::make('exploding_multiple')` already gets: no rule, no
    // writable name, so the column can be neither written nor cleared.
    $components = [
        TagsInput::make('boom')->nestedRecursiveRules(
            ['max:20'],
            fn () => throw new RuntimeException('deliberately broken nested-rule gate'),
        ),
    ];

    expect(RuleExtractor::fromComponents($components))->toBe([])
        ->and(WritableNames::of($components))->toBe([]);
});

it('still admits a tags field whose nested rules are merely absent', function () {
    // The control group for the refusal above: "cannot answer" must not
    // collapse into "answered nothing". A component that does not implement
    // the interface at all, and one that implements it with no rules
    // declared, are both ordinary writable fields.
    expect(WritableNames::of([TagsInput::make('plain_tags')]))->toBe(['plain_tags'])
        ->and(WritableNames::of([\Filament\Forms\Components\TextInput::make('name')]))->toBe(['name']);
});

it('lets a tags field inside a disabled section contribute no rule and no writable name', function () {
    $components = [Section::make('Restricted')->disabled()->schema([labelsTagsInput()])];

    expect(RuleExtractor::fromComponents($components))->toBe([])
        ->and(WritableNames::of($components))->toBe([]);
});

it('persists submitted tags as a list through the unmodified write path', function () {
    // Three distinct tags, not two: the value is one array attribute, and a
    // fixture with fewer cannot show an ordering or element-dropping bug.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'labels' => ['urgent', 'billing', 'vip'],
        ])
        ->assertOk();

    expect($banner->fresh()->labels)->toBe(['urgent', 'billing', 'vip']);
});

it('422s on a tag that breaks the per-tag rule, keyed by index', function () {
    // `labels.<index>`-shaped, so a client can put the error on the offending
    // tag rather than on the whole field. The offender is at index 1, not 0:
    // a single-tag payload cannot tell "keyed by index" from "keyed by the
    // only index there is".
    $banner = seedBanner();

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'labels' => ['urgent', str_repeat('x', 21), 'vip'],
        ])
        ->assertStatus(422);

    expect(array_keys($response->json('errors')))->toContain('labels.1');

    expect($banner->fresh()->labels)->toBeNull();
});

it('refuses a delimited string where the contract promises a list', function () {
    // The wire value is a List<String> in EVERY case, separator or not. A
    // client sending the panel's persisted delimited form back would
    // otherwise store a string in a column the read path hands back as an
    // array, and slip past every per-tag rule on the way.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'labels' => 'urgent,billing',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['labels']);

    expect($banner->fresh()->labels)->toBeNull();
});
