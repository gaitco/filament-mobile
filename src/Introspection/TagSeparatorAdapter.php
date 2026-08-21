<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Gait\MobileCore\Ports\TagSeparatorSource;

/** TagSeparators::forResource(), shaped as the Core port. */
final class TagSeparatorAdapter implements TagSeparatorSource
{
    public function forResource(string $resourceClass): array
    {
        return TagSeparators::forResource($resourceClass);
    }
}
