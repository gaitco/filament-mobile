<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Gait\FilamentMobile\Dashboard\WidgetReader;
use Gait\FilamentMobile\Introspection\WalkWarnings;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's live values.
 *
 * Deliberately not part of `/schema`: a widget's numbers are computed per
 * request, and `/schema` earns its keep by being static and cacheable. A host
 * with no dashboard pays nothing for this endpoint's existence.
 *
 * There is no resource-level gate here because there is no resource — a
 * widget's own `canView()` is the only authorization Filament defines for
 * it, and `WidgetReader` applies exactly that, per widget, per user.
 */
final class DashboardController
{
    public function __invoke(Request $request): JsonResponse
    {
        $warnings = new WalkWarnings();
        $reader = new WidgetReader($warnings);

        $widgets = [];

        foreach ((array) config('filament-mobile.widgets', []) as $class) {
            if (! is_string($class)) {
                continue;
            }

            $node = $reader->read($class);

            // Absence is the contract: a widget that is denied, broken or
            // unsupported is simply not here. The client renders what it got.
            if ($node !== null) {
                $widgets[] = $node;
            }
        }

        // Same closed 'ltr'/'rtl' answer /schema's `panel.direction` publishes
        // — one shared method, not a second copy of the normalising rule.
        $body = ['widgets' => $widgets, 'direction' => PanelSchemaBuilder::direction()];

        // Read at request time, not cached at boot — same as schema(), and
        // for the same reason: a test flips the environment after booting.
        if (! app()->environment('production')) {
            $body['_warnings'] = $warnings->all();
        }

        return response()->json($body);
    }
}
