<?php

declare(strict_types=1);

use Gait\MobileCore\SettledSchema;

/**
 * A stand-in for a form: `build` reports which names it would write for a
 * given state, so a test can express "B is writable only when A says unlock"
 * without constructing a Filament schema. The real builds are exercised end to
 * end by Task 3's feature tests.
 */
function fakeBuild(array $writableRules): callable
{
    return function (array $state) use ($writableRules): array {
        $writable = [];

        foreach ($writableRules as $name => $predicate) {
            if ($predicate($state)) {
                $writable[] = $name;
            }
        }

        return ['components' => ['state' => $state], 'writable' => $writable];
    };
}

it('settles in one pass when every submitted key is writable', function () {
    $settled = SettledSchema::settle(
        submitted: ['a' => 1],
        trusted: [],
        build: fakeBuild(['a' => fn () => true]),
    );

    expect($settled->passes())->toBe(1)
        ->and($settled->state()['a'])->toBe(1);
});

it('resets a key the schema will not write, so it cannot steer a gate', function () {
    // The mainstream idiom: `locked` is writable only while `status` is not
    // "approved". `status` itself is NOT a name this schema writes — it is a
    // Hidden, an unmapped component or simply absent from the form — so the
    // crafted "draft" must not be what the gate reads.
    $settled = SettledSchema::settle(
        submitted: ['status' => 'draft', 'locked' => 'crafted'],
        trusted: ['status' => 'approved', 'locked' => 'stored'],
        build: fakeBuild([
            'locked' => fn (array $s) => ($s['status'] ?? null) !== 'approved',
        ]),
    );

    // `status` never reaches the database, so it never reaches the gate...
    expect($settled->state()['status'])->toBe('approved')
        // ...and `locked` was dropped from the allow-set on the pass where the
        // gate closed, and never returns.
        ->and($settled->state()['locked'])->toBe('stored');
});

it('lets a WRITABLE sibling open a gate, exactly as the form itself would', function () {
    // The counter-test to the one above, and the reason the rule is "writable"
    // and not "unchanged": a user editing `status` in the Filament UI enables
    // `locked` and saves both. Resetting here would break the mainstream form.
    $settled = SettledSchema::settle(
        submitted: ['status' => 'draft', 'locked' => 'legitimate'],
        trusted: ['status' => 'approved', 'locked' => 'stored'],
        build: fakeBuild([
            'status' => fn () => true,
            'locked' => fn (array $s) => ($s['status'] ?? null) !== 'approved',
        ]),
    );

    expect($settled->state()['status'])->toBe('draft')
        ->and($settled->state()['locked'])->toBe('legitimate');
});

it('resets a key the schema never writes at all', function () {
    // A Hidden, an unmapped component, a file: none is "refused", all three
    // are client-controlled, and none may steer a gate. `build` simply never
    // names them.
    $settled = SettledSchema::settle(
        submitted: ['kind' => 'unlock', 'name' => 'R2'],
        trusted: ['kind' => 'promo'],
        build: fakeBuild(['name' => fn () => true]),
    );

    expect($settled->state()['kind'])->toBe('promo');
});

it('needs a THIRD pass when a gate closes only after the first reset', function () {
    // The case two passes cannot handle, and the reason this iterates.
    // Pass 1: `a` crafted, so `b` writable, so `c` writable.
    // Pass 2: `a` reset (never writable) -> `b` closes. But `c` was still
    //         reading `b`'s CRAFTED value during that pass.
    // Pass 3: `b` reset -> `c` closes. Only now is the state clean.
    $settled = SettledSchema::settle(
        submitted: ['a' => 'craft', 'b' => 'craft', 'c' => 'craft'],
        trusted: ['a' => 'safe', 'b' => 'safe', 'c' => 'safe'],
        build: fakeBuild([
            'b' => fn (array $s) => ($s['a'] ?? null) === 'craft',
            'c' => fn (array $s) => ($s['b'] ?? null) === 'craft',
        ]),
    );

    expect($settled->passes())->toBeGreaterThanOrEqual(3)
        ->and($settled->state()['c'])->toBe('safe');
});

it('never re-admits a key once dropped', function () {
    // Resetting `a` may make `b`'s gate answer "writable" again. Re-admitting
    // it would reintroduce the crafted value and oscillate forever.
    $settled = SettledSchema::settle(
        submitted: ['a' => 'craft', 'b' => 'crafted-b'],
        trusted: ['a' => 'safe', 'b' => 'stored-b'],
        build: fakeBuild([
            'b' => fn (array $s) => ($s['a'] ?? null) === 'safe',
        ]),
    );

    // The state is only half of it. The FINAL build is made from state where
    // `a` is already 'safe', so it reports `b` writable again — and a caller
    // reading rules off those components alone would write the crafted value
    // after all. writable() is the accumulated allow-set, which never re-admits.
    expect($settled->state()['b'])->toBe('stored-b')
        ->and($settled->writable())->not->toContain('b');
});

it('exposes the components the FINAL pass produced', function () {
    $settled = SettledSchema::settle(
        submitted: ['a' => 'craft'],
        trusted: ['a' => 'safe'],
        build: fakeBuild([]),
    );

    expect($settled->components()['state']['a'])->toBe('safe');
});

it('resets a dotted path without disturbing its siblings', function () {
    // State is addressed by path — `caption.ar` is nested, not a flat key.
    $settled = SettledSchema::settle(
        submitted: ['caption' => ['ar' => 'crafted', 'en' => 'kept']],
        trusted: ['caption.ar' => 'stored'],
        build: fakeBuild(['caption.en' => fn () => true]),
    );

    expect($settled->state()['caption']['ar'])->toBe('stored')
        ->and($settled->state()['caption']['en'])->toBe('kept');
});

it('drops a non-writable path that has no trusted value', function () {
    // On create a field may have no default. Keeping the submitted value would
    // defeat the reset entirely, so the key simply goes.
    $settled = SettledSchema::settle(
        submitted: ['secret' => 'crafted'],
        trusted: [],
        build: fakeBuild([]),
    );

    expect($settled->state())->not->toHaveKey('secret');
});

it('fails closed when the bound is reached', function () {
    // The allow-set shrinks by at least one name per non-fixpoint pass, so the
    // bound is |writable names| + 2 — a real ceiling, derived from pass 1, not
    // an unreachable one. Passing `maxPasses` explicitly PINS it instead of
    // letting it derive (see the parameter doc on SettledSchema::settle()),
    // which is the only honest way to exercise the throw here: this one needs
    // two passes and is given one.
    expect(fn () => SettledSchema::settle(
        submitted: ['a' => 'crafted'],
        trusted: ['a' => 'stored'],
        build: fakeBuild([]),
        maxPasses: 1,
    ))->toThrow(\RuntimeException::class);
});

it('derives a higher cap than the default literal for a form with more writable names', function () {
    // The default literal (32) is only a floor. A chain of 39 gates —
    // x2 writable iff x1 is crafted, x3 iff x2 is crafted, and so on —
    // reports 39 writable names on pass 1 and needs 41 passes to fully
    // settle: more than the old hard-coded 32, which would have thrown here
    // even though nothing is actually wrong with the form. The derived cap,
    // max(32, 39 + 2) = 41, covers it without a caller ever raising anything.
    $count = 40;
    $writableRules = [];

    for ($i = 2; $i <= $count; $i++) {
        $prev = $i - 1;
        $writableRules["x{$i}"] = fn (array $s) => ($s["x{$prev}"] ?? null) === 'craft';
    }

    $submitted = [];
    $trusted = [];

    for ($i = 1; $i <= $count; $i++) {
        $submitted["x{$i}"] = 'craft';
        $trusted["x{$i}"] = 'safe';
    }

    $settled = SettledSchema::settle(
        submitted: $submitted,
        trusted: $trusted,
        build: fakeBuild($writableRules),
    );

    expect($settled->passes())->toBeGreaterThan(32)
        ->and($settled->state()["x{$count}"])->toBe('safe');
});
