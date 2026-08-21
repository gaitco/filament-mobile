<?php

declare(strict_types=1);

namespace Gait\MobileCore\Ports;

/**
 * The serializer's window onto rich-text conversion. Shaped by what
 * RecordSerializer consumes — see its $richEnvelopes memo and richPathsFor()
 * for the calling contract (null means "conversion degraded, publish no
 * sibling"; both derived shapes come from ONE conversion).
 */
interface RichTextEnvelopes
{
    /** @return array{doc: array<string, mixed>, text: string}|null */
    public function envelopeFor(string $raw): ?array;

    /**
     * Rich attributes the MODEL itself declares, independent of any schema.
     *
     * @param  class-string  $modelClass
     * @return list<string>
     */
    public function attributesFor(string $modelClass): array;
}
