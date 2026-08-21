<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Throwable;

/**
 * The Spatie medialibrary components, named by FQCN like ComponentTypeMap's
 * own map — this package never imports a medialibrary or Filament-Spatie
 * class, in `src/` or here.
 */
final class MediaFields
{
    public const string UPLOAD = 'Filament\\Forms\\Components\\SpatieMediaLibraryFileUpload';

    public const string ENTRY = 'Filament\\Infolists\\Components\\SpatieMediaLibraryImageEntry';

    public static function isMediaUpload(object $component): bool
    {
        return $component::class === self::UPLOAD;
    }

    public static function isMediaEntry(object $component): bool
    {
        return $component::class === self::ENTRY;
    }

    /**
     * The declared collection name, `'default'` when the component never
     * called `->collection()`, or null when the gate THREW — fail closed,
     * the same rule every other closure-backed read in this package follows.
     * The walker publishes `readOnly: true` on that null; RuleExtractor and
     * pathsIn() below both simply omit the field rather than guess.
     */
    public static function collectionOf(object $component): ?string
    {
        if (! method_exists($component, 'getCollection')) {
            return 'default';
        }

        try {
            return $component->getCollection() ?? 'default';
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Leaf name => collection + multiplicity for every media component
     * (upload or entry) in a component tree — the exact shape
     * `RecordSerializer::withMediaPaths()` (Task 3) consumes to know which
     * paths get a `.__media` sibling and whether that sibling is single- or
     * multi-valued.
     *
     * A component whose collection gate throws is simply omitted here — the
     * walker separately publishes that field `readOnly: true` (a server-side
     * concern), so the serializer never has to guess a collection to read
     * media from.
     *
     * ponytail: media components never nest inside a repeater's item
     * template in this slice, so no per-item (`items.*.field`) naming is
     * handled here — a nested one is walked like any other leaf and would
     * collide by name with a top-level one of the same name. Not reachable
     * from any fixture today; revisit if a panel nests one.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, array{collection: string, multiple: bool}>
     */
    public static function pathsIn(iterable $components): array
    {
        $paths = [];

        foreach (self::leaves($components) as $name => $component) {
            $isUpload = self::isMediaUpload($component);

            if (! $isUpload && ! self::isMediaEntry($component)) {
                continue;
            }

            $collection = self::collectionOf($component);

            if ($collection === null) {
                continue;
            }

            $paths[$name] = [
                'collection' => $collection,
                // An entry only ever displays one image per the design
                // spec's wire shape (`SpatieMediaLibraryImageEntry` shows a
                // single record's media); multiplicity is a question only an
                // upload's own ->multiple() answers.
                'multiple' => $isUpload && self::isMultiple($component),
            ];
        }

        return $paths;
    }

    private static function isMultiple(object $component): bool
    {
        if (! method_exists($component, 'isMultiple')) {
            return false;
        }

        try {
            return (bool) $component->isMultiple();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The same wired descent `ChildComponents::of()` gives the walker and
     * RuleExtractor — reused rather than re-derived, so a component read out
     * of a detached container (which throws on most accessors) is not
     * mistaken here for "no children".
     *
     * @param  iterable<mixed>  $components
     * @return iterable<string, object>
     */
    private static function leaves(iterable $components): iterable
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $name = self::nameOf($component);

            if (is_string($name) && $name !== '') {
                yield $name => $component;
            }

            yield from self::leaves(ChildComponents::of($component));
        }
    }

    private static function nameOf(object $component): ?string
    {
        if (! method_exists($component, 'getName')) {
            return null;
        }

        try {
            $name = $component->getName();
        } catch (Throwable) {
            return null;
        }

        return is_string($name) ? $name : null;
    }
}
