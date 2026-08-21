<?php

declare(strict_types=1);

namespace Gait\MobileCore;

/**
 * A `MobileCard` derived from a relation manager's declared columns.
 *
 * A resource's own card is declared by hand because a Resource's `table()`
 * cannot be built without a Livewire host. A relation manager's can — it IS
 * the host — so the host is not made to restate what the panel already
 * declares. Where the derivation produces nothing usable, the host declares
 * one explicitly through `MobileResource::relationCard()`: the same escape
 * hatch, not a different model.
 *
 * Only the first two columns are used this slice. A relation whose meaning
 * lives in its third column is named in the spec's known weaknesses.
 */
final class RelationCard
{
    /** @param list<array{name: string, label: string}> $columns */
    public static function fromColumns(array $columns): ?MobileCard
    {
        if ($columns === []) {
            return null;
        }

        $card = MobileCard::make()->title($columns[0]['name']);

        if (isset($columns[1])) {
            $card = $card->subtitle($columns[1]['name']);
        }

        return $card;
    }
}
