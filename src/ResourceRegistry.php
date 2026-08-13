<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The opt-in gate. A Filament resource is mobile only if it declares a static
 * `mobile()`; everything else in the panel stays invisible to the API.
 */
final class ResourceRegistry
{
    /** @return array<class-string, MobileResource> */
    public function mobileResources(): array
    {
        $resources = [];

        foreach ($this->allResourceClasses() as $class) {
            if (! is_string($class) || ! method_exists($class, 'mobile')) {
                continue;
            }

            $resources[$class] = $class::mobile();
        }

        return $resources;
    }

    /**
     * The mobile resource behind a route segment, or null when the segment
     * names nothing this panel serves — which includes every resource that
     * declared no mobile(), and is the endpoint's 404.
     *
     * @return array{class-string, MobileResource}|null
     */
    public function findByKey(string $key): ?array
    {
        foreach ($this->mobileResources() as $class => $mobile) {
            if ($this->keyFor($class) === $key) {
                return [$class, $mobile];
            }
        }

        return null;
    }

    /**
     * The mobile resource that OWNS a model — the one whose `form()` declares
     * that model's column shapes — or null when the answer is not unambiguous.
     *
     * Deliberately not "the first match". A model may be served by several
     * resources (this package's own fixtures have five over `Company`) and by
     * none, and both are ordinary panel shapes rather than errors. The caller
     * is RelationController, whose child rows belong to whatever resource
     * writes them, and its fallback for a null answer is the raw stored value
     * — the behaviour every relation had before this existed. Guessing between
     * two resources would pick a form at random and could publish a column in
     * the wrong shape, which is worse than not answering.
     *
     * P9 gave the null answer a second job: it is also what switches a
     * relation's WRITE endpoints off (404) and keeps the `resource` key out
     * of the relation's /schema node — absence means unavailable, and an
     * ambiguous form is no form at all.
     *
     * @param  class-string  $model
     * @return class-string|null
     */
    public function findByModel(string $model): ?string
    {
        $owners = $this->ownersOf($model);

        return count($owners) === 1 ? $owners[0] : null;
    }

    /**
     * EVERY mobile resource serving a model, so a caller can tell "none" from
     * "several" — findByModel() collapses both into the same null, and
     * `doctor` names a relation whose writes are off with the reason, which
     * needs the count.
     *
     * @param  class-string  $model
     * @return list<class-string>
     */
    public function ownersOf(string $model): array
    {
        $owners = [];

        foreach ($this->mobileResources() as $class => $mobile) {
            if ($class::getModel() === $model) {
                $owners[] = $class;
            }
        }

        return $owners;
    }

    /**
     * The resource's Filament slug, so a mobile key matches the web panel's
     * URL for the same resource. A nested resource's slug ('blog/posts')
     * becomes 'blog-posts': the key is a single route segment.
     *
     * @param  class-string  $class
     */
    public function keyFor(string $class): string
    {
        return str_replace('/', '-', $class::getSlug());
    }

    /**
     * An explicit `filament-mobile.resources` list wins when configured — it
     * is how tests (and any app without a booted panel, e.g. a queue worker
     * or an artisan command) name their resources. Otherwise the registered
     * panel is the source of truth, so nothing has to be listed twice.
     *
     * Public because `doctor` reports the resources that declared *no*
     * mobile() — the ones the panel holds and the API cannot see — and that
     * list only exists here.
     *
     * @return iterable<class-string>
     */
    public function allResourceClasses(): iterable
    {
        $configured = config('filament-mobile.resources');

        if (is_array($configured)) {
            return $configured;
        }

        try {
            // NOT `getPanel(isStrict: false)`: that delegates to
            // PanelRegistry::get($id = null), whose very first line is
            // `if ($id === null) return null;` — so it resolves nothing in
            // *any* context, HTTP or console, and this package discovered zero
            // resources in every real app. Every test set
            // `filament-mobile.resources` explicitly, so the branch below was
            // never exercised until the pilot ran doctor and got an empty
            // panel with a clean exit code. `getCurrentOrDefaultPanel()` is
            // Filament's own accessor for "the panel serving this request, or
            // the default one" — the mobile routes live outside any panel's
            // middleware, so the current panel is never set and the default is
            // the only correct answer.
            return Filament::getCurrentOrDefaultPanel()?->getResources() ?? [];
        } catch (Throwable $e) {
            // No panel is registered, or Filament itself is not booted.
            // An empty panel document is the honest answer; `doctor` reports
            // the misconfiguration.
            //
            // Logged because the symptom is byte-identical to the documented
            // guard trap (README) and to a correctly-denied user: three very
            // different causes, one `"resources": []`. The log line is the
            // only thing that tells them apart.
            Log::warning('[filament-mobile] could not read the Filament panel, serving no resources: '
                . $e->getMessage());

            return [];
        }
    }
}
