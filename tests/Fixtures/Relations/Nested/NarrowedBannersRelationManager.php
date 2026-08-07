<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Relations\Nested;

use Gait\FilamentMobile\Tests\Fixtures\Relations\NarrowedBannersRelationManager as Sibling;

/**
 * Deliberately the same class basename as its sibling one namespace up, and
 * deliberately also refused. Two refusals keyed by basename collapse into
 * one, and `doctor` is the only channel that tells a panel author why a
 * relation vanished — so a lost refusal is an invisible relation.
 */
class NarrowedBannersRelationManager extends Sibling {}
