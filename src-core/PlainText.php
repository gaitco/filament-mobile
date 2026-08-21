<?php

declare(strict_types=1);

namespace Gait\MobileCore;

/**
 * Flattens markup to the string a sighted web user reads. Two callers need
 * exactly this: `SchemaWalker::flatOptions()` for an `allowHtml()` label, and
 * `RichContent::envelopeFor()` for a rich column shown on a card. One
 * implementation, so the two cannot drift — P6d shipped a defect precisely
 * because the same rule was written twice and the copies drifted.
 */
final class PlainText
{
    /**
     * The text inside an `allowHtml()` label. Tags go, entities decode, and
     * the whitespace an SVG's source newlines leave behind is collapsed — a
     * phone renders a string, so what it needs is the string a sighted web
     * user reads, not the markup that draws it.
     */
    public static function of(string $value): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
    }
}
