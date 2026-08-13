<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Write;

use Illuminate\Support\Arr;

/**
 * A schema built from state no gate can have been steered through.
 *
 * A schema built from client-controlled state can have a gate flipped by
 * crafting a sibling's value — but only where that sibling is a name the
 * schema will never WRITE. A writable sibling is not an escalation: if
 * `status` is itself persisted, submitting `draft` takes, exactly as
 * Filament's own UI would. The escalation needs a steering name the client
 * can set but the server will never persist — `Hidden::make('kind')` plus
 * `disabled(fn (Get $get) => $get('kind') !== 'unlock')` — because only then
 * does a crafted payload reach a state no UI session could produce.
 *
 * The rule: a submitted value survives only if the schema will WRITE that
 * name. If a value cannot reach the database, it has no business steering
 * which other values do. That covers refused fields, `Hidden` fields, unmapped
 * components and `file` fields in one stroke — a deny-set built from refusals
 * misses the last three entirely.
 *
 * It iterates because one pass is not enough: dropping `a` can close `b`'s
 * gate, and `b`'s crafted value was still in the state during the pass that
 * closed it. The allow-set only ever shrinks, so the loop terminates.
 */
final class SettledSchema
{
    /**
     * The literal floor. The allow-set shrinks by at least one name per
     * non-fixpoint pass, so the true bound is |writable names| + 2 — this is
     * only ever a starting point, derived upward on pass 1 for forms with
     * more writable names than it. Kept as a named constant rather than
     * inlined so the derivation below and the parameter default cannot drift
     * apart.
     *
     * The `int` is typed again, which it could not be while this package
     * declared `php: ^8.2`: typed constants are 8.3, and a feature above the
     * floor is a parse error rather than a degradation — the whole file fails
     * to load, so nothing in the write path runs. It shipped exactly that way
     * in v0.3.0–v0.5.0. The floor is now `^8.4`, the version this repo is
     * developed on, so the gap that hid it no longer exists.
     */
    private const int DEFAULT_MAX_PASSES = 32;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, string>  $writable
     */
    private function __construct(
        private readonly mixed $components,
        private readonly array $state,
        private readonly array $writable,
        private readonly int $passes,
    ) {}

    /**
     * @param  array<string, mixed>  $submitted  client-controlled state
     * @param  array<string, mixed>  $trusted    path => value; the reset target
     * @param  callable(array<string, mixed>): array{components: mixed, writable: array<int, string>}  $build
     * @param  int  $maxPasses  The literal default (32) is a floor, derived
     *                          upward on pass 1 to |writable names| + 2 when
     *                          that is larger. Passing an explicit value
     *                          PINS the ceiling exactly instead — no
     *                          derivation — which is what lets a test force
     *                          the fail-closed throw deliberately.
     */
    public static function settle(
        array $submitted,
        array $trusted,
        callable $build,
        int $maxPasses = self::DEFAULT_MAX_PASSES,
    ): self {
        // Everything is trusted until the schema says otherwise. `null` is
        // "not yet narrowed", which is not the same as "nothing allowed".
        $allowed = null;
        $state = $submitted;
        $pass = 0;
        $cap = $maxPasses;

        while (true) {
            $pass++;

            if ($pass > $cap) {
                // Unreachable unless the monotonicity assumption is broken,
                // which would be a bug here and not a client's doing. Failing
                // closed costs a 500; writing after an unconverged loop would
                // write against state this class cannot vouch for.
                //
                // ponytail: accepted residual. On `/state` — a read, everywhere
                // else in this package — that 500 is a harder failure than the
                // warning-and-degrade this endpoint otherwise gives every other
                // broken gate. Accepted anyway: the alternative is falling back
                // to the un-settled build, which republishes the exact
                // divergence this class exists to close. The cap is derived,
                // not a class invariant: the allow-set shrinks by at least one
                // non-fixpoint name per pass, so the true bound is |writable
                // names| + 2, and pass 1 below raises `$cap` to that whenever
                // it exceeds the literal floor. A caller can still pin a lower
                // ceiling explicitly (see the parameter doc); only then can
                // this throw fire for a form the derivation would otherwise
                // have covered.
                throw new \RuntimeException(
                    'filament-mobile: the schema did not settle in ' . $cap
                    . ' passes. Refusing to write against state that may still '
                    . 'carry a crafted value.',
                );
            }

            $built = $build($state);

            // Derived once, off pass 1's writable count — the widest the
            // allow-set is ever going to be, since every later pass can only
            // shrink it. Only when the caller left the literal default in
            // place: an explicit $maxPasses is a pin, not a floor, and
            // deriving over it would silently defeat a caller's (or a test's)
            // deliberately low ceiling.
            if ($pass === 1 && $maxPasses === self::DEFAULT_MAX_PASSES) {
                $cap = max(self::DEFAULT_MAX_PASSES, count($built['writable']) + 2);
            }

            // Shrink only. Re-admitting a name whose gate reopened *because*
            // its crafted value left the state would reintroduce that value
            // and oscillate forever.
            $next = $allowed === null
                ? $built['writable']
                : array_values(array_intersect($allowed, $built['writable']));

            $settled = self::reset($submitted, $next, $trusted);

            // The fixpoint is on the state, not on the allow-set: this build
            // is only usable if the narrowing it produced would not have
            // changed the state it was built from.
            if ($settled === $state) {
                return new self($built['components'], $state, $next, $pass);
            }

            $allowed = $next;
            $state = $settled;
        }
    }

    public function components(): mixed
    {
        return $this->components;
    }

    /**
     * The state the final pass was built from — every non-writable path reset.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * The accumulated allow-set, which is NOT the same as asking the returned
     * components what they would write — and the difference is a data-loss bug
     * if a caller reaches for the latter.
     *
     * The allow-set only shrinks. A name dropped on an early pass stays dropped
     * even when the reset makes the final build report it writable again, and
     * that is deliberate: `dehydrated(fn (?string $state) => filled($state))`
     * reads its OWN value, so resetting that value to the stored one and
     * re-asking gets "writable" for a field whose submitted value is precisely
     * what the closure refused. Reading rules off the final build alone lets
     * that submitted `''` through, and Laravel's ConvertEmptyStringsToNull has
     * already made it a null — so the column lands NULL over the stored value.
     * That is the password-blanking bug this package already closed once.
     *
     * So a caller turning components into rules must intersect with this.
     *
     * @return array<int, string>
     */
    public function writable(): array
    {
        return $this->writable;
    }

    public function passes(): int
    {
        return $this->passes;
    }

    /**
     * Rebuild the state, keeping submitted values only for allowed paths.
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<int, string>  $allowed
     * @param  array<string, mixed>  $trusted
     * @return array<string, mixed>
     */
    private static function reset(array $submitted, array $allowed, array $trusted): array
    {
        $state = $trusted === [] ? [] : self::expand($trusted);

        foreach ($allowed as $path) {
            if (Arr::has($submitted, $path)) {
                Arr::set($state, $path, Arr::get($submitted, $path));
            }
        }

        return $state;
    }

    /**
     * `['caption.ar' => 'x']` is a path map; the state it seeds is nested.
     *
     * @param  array<string, mixed>  $trusted
     * @return array<string, mixed>
     */
    private static function expand(array $trusted): array
    {
        $state = [];

        foreach ($trusted as $path => $value) {
            Arr::set($state, $path, $value);
        }

        return $state;
    }
}
