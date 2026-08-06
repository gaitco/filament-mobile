<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\FieldPersistence;

/**
 * The isolated case Feature-level fixtures cannot reach: something with
 * `getRelationship()` returning non-null, no `isMultiple()`, and DEHYDRATING
 * NORMALLY — i.e. it does not owe its `disabled` state to anything except
 * the multi-valued-relationship check.
 *
 * No real Filament class occupies this shape reachably through this
 * package. `CheckboxList` matches "no isMultiple()" but is genuinely
 * multi-valued. `Section`/`Group`/`Grid`/`Fieldset`/`Flex`/`Form` also match
 * "no isMultiple()", are genuinely singular via
 * `EntanglesStateWithSingularRelationship`, but that same trait's
 * `relationship()` unconditionally calls `dehydrated(false)` — so every one
 * of them is ALSO caught by the pre-existing dehydrated-literal check,
 * masking whichever answer this method gives. `MorphToSelect` is the one
 * core Filament class that is genuinely singular, lacks `isMultiple()`, and
 * dehydrates normally — but it is unmapped by ComponentTypeMap, so
 * SchemaWalker drops it before FieldPersistence ever sees it.
 *
 * This stub is what a hypothetical component in that gap looks like, and
 * it is what proves the fix rather than the accident of every reachable
 * fixture being double-covered. See BannerResource's `Section::relationship
 * ('company')` and SchemaEndpointTest for why that fixture, despite being
 * the literal shape review asked for, cannot demonstrate this on its own.
 */
it('does not lock a component merely for lacking isMultiple() — the relationship must also be multi-valued', function () {
    $singularRelationshipContainer = new class {
        public function getRelationship(): object
        {
            return new stdClass();
        }

        public function isDehydrated(): bool
        {
            return true;
        }
    };

    expect(FieldPersistence::neverPersists($singularRelationshipContainer))->toBeFalse();
});
