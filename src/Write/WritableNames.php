<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Write;

use Gait\FilamentMobile\Validation\RuleExtractor;

/**
 * The names whose submitted value the write path may trust.
 *
 * A schema built from client-controlled state can have any gate flipped by
 * crafting a sibling's value, so SettledSchema rebuilds against trusted state.
 * The question is which names escape that reset, and the answer is exactly the
 * ones the write will persist: if a value cannot reach the database, it has no
 * business steering which OTHER values do.
 *
 * Deliberately NOT "the refused names". A `Hidden` is skipped by
 * ComponentTypeMap, an unmapped component is dropped by the walker, and a
 * `file` has its rule withheld — none of the three is *refused*, all three are
 * client-controlled, and a refusal-based reset leaves every one of them
 * steering gates forever. Inverting the question closes all three at once.
 */
final class WritableNames
{
    /**
     * @param  iterable<mixed>  $components
     * @return array<int, string>
     */
    public static function of(iterable $components): array
    {
        // NOT array_keys(RuleExtractor::fromComponents(...)) — since P6c a
        // repeater's rules also carry its per-item paths (`items.*.name`),
        // and Arr::has()/Arr::set() (SettledSchema::reset()) have no
        // wildcard support: a starred name here would silently drop every
        // submitted row rather than trust it. writableComponents() is the
        // same descent with the starred entries left out at the source — see
        // its docblock and the design spec's "two different name spaces".
        //
        // Relation-write names are the second door into the database: their
        // ids reach it through saveRelationships(), not the payload, so they
        // carry no rule — but they ARE persisted, and a persisted name is a
        // legitimate steering name. Without them the settle resets a
        // submitted picker to the trusted floor, and the relation pass reads
        // an empty state where the user's choice was. Disabled ones are
        // already excluded, fail closed, inside the descent.
        return array_values(array_unique([
            ...array_keys(RuleExtractor::writableComponents($components)),
            ...array_keys(RuleExtractor::relationWriteComponents($components)),
        ]));
    }
}
