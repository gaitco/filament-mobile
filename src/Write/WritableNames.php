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
        // The rules array is already the mass-assignment whitelist — only a
        // key with a rule survives $request->validate() and reaches create()
        // or update(). Reusing it is what keeps "trusted to steer a gate" and
        // "allowed to be written" from ever drifting apart.
        return array_keys(RuleExtractor::fromComponents($components));
    }
}
