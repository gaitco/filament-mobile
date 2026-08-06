<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for a host's SetLocale middleware: reads a header, sets the app
 * locale, and reports back what the request ended up running in.
 */
class LocaleProbe
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale((string) $request->header('X-Locale', 'en'));

        return $next($request)->header('X-Probe-Locale', app()->getLocale());
    }
}
