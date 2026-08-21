<?php

declare(strict_types=1);

use Filament\Forms\Components\TimePicker;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\MobileCore\WalkWarnings;

/**
 * Task 2 of P8. `TimePicker` is five lines in vendor — `extends
 * DateTimePicker`, overriding only `hasDate(): false` — so it inherits
 * `getMinDate()`, `getMaxDate()` and `hasSeconds()` unchanged. The walker's
 * date branch is therefore *widened* to `time` rather than copied, exactly as
 * P7's `radio` widened the option branch.
 *
 * What a time bound actually looks like on the wire, measured rather than
 * assumed: `getMinDate()` is `return $this->evaluate($this->minDate)`, so it
 * hands back **whatever the panel declared, unnormalised**. A panel writing
 * `->minDate('09:00')` publishes the string `"09:00"`; a panel writing
 * `->minDate(Carbon::parse('2026-01-01 09:00'))` publishes
 * `"2026-01-01 09:00:00"` (the `?string` return type stringifies the Carbon).
 * Both are covered below, because the client has to read both.
 *
 * Three fields, three different answers, for the reason DateConfigTest.php
 * spells out: a fixture where every field is bounded cannot prove that an
 * unbounded one publishes null, and a fixture with no bounds proves nothing
 * about bounds at all.
 */
function timeNode(string $name): array
{
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk([
        TimePicker::make('opens_at')
            ->minDate('09:00')
            ->maxDate('17:00')
            ->seconds(false),
        // Unbounded, and seconds left at DateTimePicker's own vendor default
        // of `true` — the opposite of `opens_at` on both counts.
        TimePicker::make('closes_at'),
        TimePicker::make('carbon_bounded')
            ->minDate(Carbon\Carbon::parse('2026-01-01 09:00')),
        TimePicker::make('exploding_time')
            ->minDate(fn () => throw new RuntimeException('boom')),
        // P13 Task 1: every step configured away from the vendor default of
        // 1, so each key has to prove it publishes its own accessor.
        TimePicker::make('stepped_slot')
            ->hoursStep(2)
            ->minutesStep(15)
            ->secondsStep(30),
        // One step closure throws while a sibling stays configured: the
        // guarded reader degrades the ONE key, never the field.
        TimePicker::make('exploding_step')
            ->hoursStep(4)
            ->minutesStep(fn () => throw new RuntimeException('boom')),
    ], 'TestResource');

    foreach ($nodes as $node) {
        if ($node['name'] === $name) {
            return $node;
        }
    }

    throw new RuntimeException("no node named {$name}");
}

it('publishes a time picker as its own type', function () {
    // Not `datetime`: ComponentTypeMap matches by exact class name, so
    // TimePicker needs its own entry despite extending DateTimePicker.
    expect(timeNode('opens_at')['type'])->toBe('time');
});

it('publishes a time picker\'s bounds', function () {
    expect(timeNode('opens_at')['config'])->toMatchArray([
        'minDate' => '09:00',
        'maxDate' => '17:00',
    ]);
});

it('publishes null bounds for a time picker that declared none', function () {
    expect(timeNode('closes_at')['config'])->toMatchArray([
        'minDate' => null,
        'maxDate' => null,
    ]);
});

it('publishes a Carbon-declared time bound as a full datetime string', function () {
    // Unnormalised, deliberately: normalising a bare time into a full
    // datetime would invent a date the panel never chose, and normalising the
    // other way would throw away one the panel did choose. The client parses
    // both shapes — see DateComponent.parseTime.
    expect(timeNode('carbon_bounded')['config']['minDate'])
        ->toBe('2026-01-01 09:00:00');
});

it('publishes seconds from the time picker itself', function () {
    expect(timeNode('opens_at')['config']['seconds'])->toBeFalse()
        ->and(timeNode('closes_at')['config']['seconds'])->toBeTrue();
});

it('degrades a throwing time bound to no bound, not a failed document', function () {
    expect(timeNode('exploding_time')['config']['minDate'])->toBeNull();
});

it('publishes the steps a time picker configured, each from its own accessor', function () {
    // getHoursStep()/getMinutesStep()/getSecondsStep() are closure-backed
    // ints defaulting to 1 (measured in vendor DateTimePicker) — publish
    // only beats the default, so an absent key MEANS 1.
    expect(timeNode('stepped_slot')['config'])->toMatchArray([
        'hoursStep' => 2,
        'minutesStep' => 15,
        'secondsStep' => 30,
    ]);
});

it('publishes no step keys on a time picker left at the vendor default of 1', function () {
    // Sparse config: `closes_at` is never stepped, so all three keys must be
    // absent — not published as 1.
    $config = timeNode('closes_at')['config'];

    expect($config)->not->toHaveKey('hoursStep')
        ->and($config)->not->toHaveKey('minutesStep')
        ->and($config)->not->toHaveKey('secondsStep');
});

it('degrades a throwing step closure to absence, never a failed document', function () {
    $config = timeNode('exploding_step')['config'];

    expect($config)->not->toHaveKey('minutesStep')
        ->and($config['hoursStep'])->toBe(4);
});
