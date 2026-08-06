<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Throwable;

/**
 * Reads a property that may be backed by a closure written for a Livewire
 * context we do not have.
 *
 * One awkward closure degrades one property to its fallback; it never fails
 * the whole /schema request. Catches Throwable rather than Exception because
 * these commonly fail with a TypeError or a call-on-null Error.
 */
final class SafeEvaluator
{
    public function __construct(private readonly WalkWarnings $warnings)
    {
    }

    public function value(
        callable $read,
        mixed $fallback,
        string $resource,
        string $component,
        string $property,
    ): mixed {
        try {
            return $read();
        } catch (Throwable $e) {
            $this->warnings->add(
                $resource,
                $component,
                "could not evaluate `{$property}`: " . $e->getMessage(),
            );

            return $fallback;
        }
    }
}
