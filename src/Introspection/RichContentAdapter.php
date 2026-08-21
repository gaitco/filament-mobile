<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Gait\MobileCore\Ports\RichTextEnvelopes;

/** RichContent's statics, shaped as the Core port. */
final class RichContentAdapter implements RichTextEnvelopes
{
    public function envelopeFor(string $raw): ?array
    {
        return RichContent::envelopeFor($raw);
    }

    public function attributesFor(string $modelClass): array
    {
        return RichContent::attributesFor($modelClass);
    }
}
