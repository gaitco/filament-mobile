<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Throwable;

/**
 * The `filament/spatie-laravel-tags-plugin` field, named by FQCN like
 * `MediaFields`' own precedent — this package never imports a
 * `spatie/laravel-tags` or tags-plugin class, in `src/` or here.
 */
final class TagFields
{
    public const string TAGS_INPUT = 'Filament\\Forms\\Components\\SpatieTagsInput';

    /**
     * The infolist twin: `SpatieTagsEntry` extends `TextEntry` (measured in
     * vendor/filament/spatie-laravel-tags-plugin/src/Infolists/Components/
     * SpatieTagsEntry.php) and shares `getType()`/`isAnyTagTypeAllowed()`
     * with the field — same `AllTagTypes` default, same `->type('x')`
     * override — so `typeOf()` below needs no branch of its own for it. The
     * premise `isSpatieTags()`'s old single-FQCN shape encoded ("no infolist
     * tags entry exists") no longer holds; see `MobilePanelController`'s
     * `tagPathsFor()`/`cardTagPaths()` for the read-path fold this enables.
     */
    public const string TAGS_ENTRY = 'Filament\\Infolists\\Components\\SpatieTagsEntry';

    /**
     * The plugin's own `AllTagTypes` marker class — never imported, matched
     * by name the same way `TAGS_INPUT` is. `SpatieTagsInput::setUp()`
     * unconditionally calls `$this->type(new AllTagTypes)`, so `getType()`
     * returns an INSTANCE by default, never null: branching on `filled()` or
     * `instanceof` (which would require the import) both miss that default.
     */
    private const string ALL_TAG_TYPES = 'Filament\\SpatieLaravelTagsPlugin\\Types\\AllTagTypes';

    public static function isSpatieTags(object $component): bool
    {
        return in_array($component::class, [self::TAGS_INPUT, self::TAGS_ENTRY], true);
    }

    /**
     * `{'any' => true, 'type' => null}` for the default any-type gate (an
     * `AllTagTypes` instance, detected by `get_class()` string compare — the
     * plugin's own `isAnyTagTypeAllowed()` uses `instanceof`, which this
     * package cannot: that would import the class it exists to avoid
     * depending on); `{'any' => false, 'type' => $s}` for a declared
     * `->type('some-string')`; null when the gate THREW or resolved to
     * anything else unrecognisable — fail closed, the same rule
     * `MediaFields::collectionOf()` follows. The walker drops the field
     * entirely on that null (design spec: a Spatie tags field, unlike media,
     * gets no `readOnly` publish — no client honours it on a `tags` node).
     *
     * @return array{any: bool, type: ?string}|null
     */
    public static function typeOf(object $component): ?array
    {
        if (! method_exists($component, 'getType')) {
            return null;
        }

        try {
            $type = $component->getType();
        } catch (Throwable) {
            return null;
        }

        if (is_object($type) && $type::class === self::ALL_TAG_TYPES) {
            return ['any' => true, 'type' => null];
        }

        if (is_string($type)) {
            return ['any' => false, 'type' => $type];
        }

        return null;
    }

    /**
     * Leaf name => any/type metadata for every Spatie tags component in a
     * component tree — the shape `RecordSerializer::withTagPaths()` (Task 3)
     * consumes verbatim to know whether to read `$record->tags` or
     * `$record->tagsWithType($type)`.
     *
     * A component whose `typeOf()` is null is simply omitted here, mirroring
     * `MediaFields::pathsIn()` — the walker separately drops that field from
     * the schema with a warning, so the serializer never has to guess a type
     * to read tags for.
     *
     * @param  iterable<mixed>  $components
     * @return array<string, array{any: bool, type: ?string}>
     */
    public static function pathsIn(iterable $components): array
    {
        $paths = [];

        foreach (self::leaves($components) as $name => $component) {
            if (! self::isSpatieTags($component)) {
                continue;
            }

            $meta = self::typeOf($component);

            if ($meta === null) {
                continue;
            }

            $paths[$name] = $meta;
        }

        return $paths;
    }

    /**
     * The same wired descent `ChildComponents::of()` gives the walker and
     * RuleExtractor — reused rather than re-derived, so a component read out
     * of a detached container (which throws on most accessors) is not
     * mistaken here for "no children". Copied from `MediaFields::leaves()`
     * rather than shared, the same way that class does not share it with
     * anything else — a private, per-file helper on purpose.
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
