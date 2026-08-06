<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Throwable;

/**
 * The `->default()` values a resource's form applies when creating a record.
 *
 * Filament's own CreateRecord fills the form with these before it saves, so a
 * mobile create that ignores them writes a row the web panel would never have
 * written. The write pilot measured the consequence on 3 of 33 resources:
 * `Hidden::make('type')->default(UserType::Client)` is how a resource stamps a
 * record as *belonging* to it, and without the default the row lands outside
 * the resource's own `getEloquentQuery()` — created with a 201, then a 404 on
 * every subsequent read, update and delete. An orphan the API cannot reach.
 *
 * `Hidden` is included deliberately, even though the walker skips it: the whole
 * point of a hidden field is that the client never sees it and so can never
 * send it. A `file` field is excluded for the same reason RuleExtractor
 * withholds its rule — the write path never writes one.
 */
final class FormDefaults
{
    /**
     * Flat `path => value`, never a nested tree: a translatable field is named
     * `title.ar`, which is a path everywhere else in this package —
     * RuleExtractor emits it as a path and the validator reads it as one — but
     * nesting it here would force the caller to merge two arrays, and PHP has
     * no array merge that is correct for both a map and a list. The caller
     * applies these one path at a time instead. See
     * MobilePanelController::fillMissingPaths().
     *
     * @param  iterable<mixed>  $components
     * @return array<string, mixed>
     */
    public static function fromComponents(iterable $components): array
    {
        $defaults = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $defaults = [...$defaults, ...self::forComponent($component)];
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private static function forComponent(object $component): array
    {
        $type = ComponentTypeMap::for($component);

        // The same gate the rule extractor applies, and for a reason review had
        // to point out: a default is the *other* way a value reaches the row,
        // so guarding only the validated payload guards nothing.
        // `Hidden::make('x')->hidden()->default('leak')` and a `default()`
        // inside a `->disabled()` section both walked straight past the first
        // version of this class. Filament's own CreateRecord fills defaults
        // into state and *then* dehydrates, which drops both.
        //
        // On containers as well as leaves: `disabled()` is inherited by every
        // field inside a gated Section, so the container has to be refused
        // before the recursion, not each child after it.
        if (FieldPersistence::refuses($component)) {
            return [];
        }

        if ($type !== null && in_array($type, ComponentTypeMap::LAYOUT_TYPES, true)) {
            return self::fromComponents(ChildComponents::of($component));
        }

        // Unmapped is not a reason to skip: an unrenderable component still
        // carries a default the panel would have written. A `file` field is
        // the one exclusion — see the class docblock.
        if ($type === 'file') {
            return [];
        }

        $name = self::read($component, 'getName');
        $default = self::read($component, 'getDefaultState');

        if (! is_string($name) || $name === '' || $default === null) {
            return [];
        }

        return [$name => $default];
    }

    /**
     * Accessors on a component the schema has not wired up throw, exactly as
     * they do for the walker and the rule extractor. A default that cannot be
     * read simply is not applied.
     */
    private static function read(object $component, string $method): mixed
    {
        if (! method_exists($component, $method)) {
            return null;
        }

        try {
            return $component->{$method}();
        } catch (Throwable) {
            return null;
        }
    }
}
