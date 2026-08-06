<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Schemas\Schema;
use Throwable;

/**
 * The one way this package descends into a layout component.
 *
 * It exists because the two obvious ways are not equivalent, and picking the
 * wrong one fails silently.
 *
 * `getDefaultChildComponents()` reads the raw stored children. They come back
 * *detached* — no `$container` — and a detached component cannot reach its
 * schema, so every accessor that walks up to it throws: `getOptions()` on a
 * relationship select, `isDehydrated()` on a field whose closure needs its own
 * state, `isDisabled()` inherited from a gated section. Each caller catches
 * that and falls back to a default, which reads as "no options", "not
 * disabled", "dehydrated" — three wrong answers that look like ordinary ones.
 *
 * `getChildSchema()` returns the wired schema, whose components carry their
 * container and answer all of the above correctly. It needs *this* component's
 * container in turn, so it throws for a component built outside a Schema — a
 * bare unit-test fixture, or a leaf read before the tree is assembled. That is
 * the only case the raw children are still the best available answer.
 *
 * `withHidden: true` is not optional: `Schema::getComponents()` filters hidden
 * components by default, and /state's contract is that a hidden field arrives
 * present and flagged rather than missing.
 *
 * The write pilot found both halves of this. A required foreign key
 * published `options: []` on 6 of 33 resources, and a `password` field whose
 * `dehydrated(fn ($state) => filled($state))` could not be evaluated was
 * accepted anyway — so `PUT {"password": ""}` blanked a stored credential.
 */
final class ChildComponents
{
    /**
     * @return iterable<mixed>
     */
    public static function of(object $component): iterable
    {
        if (method_exists($component, 'getChildSchema')) {
            try {
                $attached = $component->getChildSchema()?->getComponents(withHidden: true);

                if ($attached !== null) {
                    return $attached;
                }
            } catch (Throwable) {
                // Not attached to a Schema. Fall through to the raw children.
            }
        }

        if (! method_exists($component, 'getDefaultChildComponents')) {
            return [];
        }

        try {
            $children = $component->getDefaultChildComponents();
        } catch (Throwable) {
            return [];
        }

        // Typed `array|Schema`: a container assigned via
        // `->childComponents(Schema::make()->components([...]))` hands back a
        // Schema, which is not Traversable.
        if ($children instanceof Schema) {
            $children = $children->getComponents();
        }

        return is_iterable($children) ? $children : [];
    }
}
