<?php

declare(strict_types=1);

namespace Gait\MobileCore\Ports;

/**
 * Which of a resource's fields store a delimited tag STRING, and by what
 * separator — resolved once per serializer (see RecordSerializer's
 * $tagSeparators memo), never per record.
 */
interface TagSeparatorSource
{
    /**
     * @param  class-string  $resourceClass
     * @return array<string, string>  field name => separator
     */
    public function forResource(string $resourceClass): array;
}
