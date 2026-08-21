<?php

declare(strict_types=1);

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\MobileCore\WalkWarnings;

/**
 * Task 1 of P8: date bounds have never reached the client. `grep -rn minDate
 * src/` returned nothing before this file existed — there was no date branch
 * in `config()` at all, so `DateComponent`'s already-written minDate/maxDate
 * parsing (Dart) was permanently fed `null`.
 *
 * Built directly on SchemaWalker, like SchemaWalkerLeafTest, rather than
 * through a registered panel resource: PostResource's own `published_on`/
 * `published_at` fixtures deliberately carry NO bounds (ContractSnapshotTest
 * asserts they merely gain an empty-valued `config` object, not real bounds —
 * see that test's diff), so a bounds-bearing fixture belongs here, not in the
 * golden panel.
 *
 * Three fields, three different answers, on purpose (see the task brief): a
 * fixture where every field is bounded cannot show that an unbounded field
 * publishes null. `published_at` carries real, different minDate/maxDate and
 * seconds on; `published_on` carries neither bound and seconds explicitly
 * off; `exploding_date`'s minDate closure throws, proving the guarded reader
 * degrades that one value rather than failing the document.
 *
 * `published_on`'s `->seconds(false)` is deliberate, not decoration:
 * `DateTimePicker`'s own `$hasSeconds` property defaults to `true` (measured
 * in vendor — `protected bool | Closure $hasSeconds = true;`), so a bare,
 * never-configured picker already answers `hasSeconds() === true`. Leaving
 * `published_on` unconfigured would make it agree with `published_at`
 * instead of contradicting it, which cannot prove the walker publishes a
 * real `false` rather than a hard-coded `true`.
 */
function dateNode(string $name): array
{
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        DateTimePicker::make('published_at')
            ->minDate('2026-01-01')
            ->maxDate('2026-12-31')
            ->seconds(),
        DatePicker::make('published_on')->seconds(false),
        DateTimePicker::make('exploding_date')
            ->minDate(fn () => throw new RuntimeException('boom')),
        // P13 Task 1: a datetime with every step moved off the vendor
        // default of 1, so each key proves it reads its own accessor.
        DateTimePicker::make('slot_at')
            ->hoursStep(2)
            ->minutesStep(30)
            ->secondsStep(15),
        // A stepped DatePicker: the accessors answer (DatePicker inherits
        // them from DateTimePicker), but a date has no time grid, so a
        // `date` node must publish none of the three keys regardless.
        DatePicker::make('stepped_on')->minutesStep(15),
        // Edge-sweep pin: a BOUNDED date node publishes exactly what the
        // inherited accessors say — declared bounds, and `seconds: true`
        // from `$hasSeconds`'s vendor default the picker never touched.
        // Pinned as-is so a Filament upgrade changing it diffs this test.
        DatePicker::make('bounded_on')
            ->minDate('2026-01-01')
            ->maxDate('2026-12-31'),
    ], 'TestResource');

    foreach ($nodes as $node) {
        if ($node['name'] === $name) {
            return $node;
        }
    }

    throw new RuntimeException("no node named {$name}");
}

it('publishes the bounds a date picker declared', function () {
    // getMinDate()/getMaxDate() return ?string on DateTimePicker — measured in
    // vendor — so no serialisation is needed.
    expect(dateNode('published_at')['config'])->toMatchArray([
        'minDate' => '2026-01-01',
        'maxDate' => '2026-12-31',
    ]);
});

it('publishes null bounds for a field that declared none', function () {
    // The unbounded case, proven separately from the bounded one above — a
    // fixture where every field carries bounds could never show this.
    expect(dateNode('published_on')['config'])->toMatchArray([
        'minDate' => null,
        'maxDate' => null,
    ]);
});

it('publishes seconds from the picker itself', function () {
    expect(dateNode('published_at')['config']['seconds'])->toBeTrue()
        ->and(dateNode('published_on')['config']['seconds'])->toBeFalse();
});

it('degrades a throwing bound closure to no bound, not a failed document', function () {
    expect(dateNode('exploding_date')['config']['minDate'])->toBeNull();
});

it('publishes the steps a datetime picker configured, each from its own accessor', function () {
    expect(dateNode('slot_at')['config'])->toMatchArray([
        'hoursStep' => 2,
        'minutesStep' => 30,
        'secondsStep' => 15,
    ]);
});

it('publishes no step keys on a datetime picker left at the vendor default of 1', function () {
    $config = dateNode('published_at')['config'];

    expect($config)->not->toHaveKey('hoursStep')
        ->and($config)->not->toHaveKey('minutesStep')
        ->and($config)->not->toHaveKey('secondsStep');
});

it('publishes no step keys on a date node, even a stepped one', function () {
    // DatePicker inherits the step accessors from DateTimePicker — this one
    // ANSWERS 15 for minutesStep — but a date has no time grid, so the
    // walker publishes none of the three on a `date` node at all.
    $config = dateNode('stepped_on')['config'];

    expect($config)->not->toHaveKey('hoursStep')
        ->and($config)->not->toHaveKey('minutesStep')
        ->and($config)->not->toHaveKey('secondsStep');
});

it('publishes a date node\'s declared bounds and inherited seconds default as-is', function () {
    expect(dateNode('bounded_on')['config'])->toBe([
        'minDate' => '2026-01-01',
        'maxDate' => '2026-12-31',
        'seconds' => true,
    ]);
});
