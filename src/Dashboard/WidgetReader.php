<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Dashboard;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\Widget;
use Gait\FilamentMobile\Introspection\SafeEvaluator;
use Gait\FilamentMobile\Introspection\WalkWarnings;
use Illuminate\Contracts\Support\Htmlable;
use ReflectionMethod;
use Throwable;

/**
 * Reads one configured dashboard widget into the wire's shape, or refuses.
 *
 * Every refusal returns null and every refusal is silent to the client: a
 * widget the user may not view, one whose gate threw, and one whose query
 * threw are indistinguishable in the payload, because all three mean "this
 * card is not available to you right now". The reasons go to warnings, which
 * only a developer sees.
 *
 * Filament's data accessors are `protected` — `getStats()`, `getType()`,
 * `getData()` — so reading them needs reflection. That is the same trade
 * `FieldPersistence` already makes, and it fails the same way: a Filament
 * release that renames one degrades that widget rather than the request, and
 * the fixtures on the version matrix are what turn the rename red.
 *
 * Headless construction (`new $class()`) was verified against this
 * package's Filament version before writing this file — both
 * `StatsOverviewWidget` and `ChartWidget` subclasses construct cleanly with
 * no Livewire lifecycle, so no escape hatch like `HeadlessSchemaHost` is
 * needed here. Construction alone is not the whole lifecycle, though:
 * `ChartWidget::mount()` is a real hook a real widget can depend on (it seeds
 * `$filter` and friends), so this class calls `mount()` itself — inside the
 * same degrade-this-widget guard as everything else — before reading data.
 */
final class WidgetReader
{
    private readonly SafeEvaluator $evaluator;

    public function __construct(private readonly WalkWarnings $warnings)
    {
        $this->evaluator = new SafeEvaluator($warnings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $class): ?array
    {
        if (! $this->isSupported($class)) {
            return null;
        }

        // Fail closed, like every other gate in this package: a canView()
        // that cannot answer refuses rather than admits.
        try {
            if (! $class::canView()) {
                return null;
            }
        } catch (Throwable $e) {
            $this->warn($class, 'canView() threw: ' . $e->getMessage());

            return null;
        }

        try {
            $widget = new $class();
        } catch (Throwable $e) {
            $this->warn($class, 'could not be constructed: ' . $e->getMessage());

            return null;
        }

        if ($mountError = $this->tryMount($widget)) {
            $this->warn($class, 'mount() threw: ' . $mountError->getMessage());

            return null;
        }

        return $widget instanceof ChartWidget
            ? $this->chart($widget, $class)
            : $this->stats($widget, $class);
    }

    /**
     * Configuration problems, for `filament-mobile:doctor` — never a runtime
     * concern. A widget refused at runtime (denied, throwing) is NOT a
     * configuration problem: it is the system working — `canView()` is
     * deliberately never called here.
     *
     * `doctor` is a dev-time diagnostic, so unlike `read()` it is allowed to
     * attempt construction and a real data read: that is exactly how it
     * catches "this widget cannot be read headlessly" before a user does.
     *
     * @return list<string>
     */
    public function problems(string $class): array
    {
        // class_exists() runs the autoloader, and an autoload can THROW — a
        // parse error in the widget file, a parent class from a removed
        // package. That must be a finding, never a doctor crash.
        try {
            if (! class_exists($class)) {
                return ["{$class}: no such class."];
            }

            if (! is_subclass_of($class, Widget::class)) {
                return ["{$class}: not a Filament widget."];
            }

            $isChart = is_subclass_of($class, ChartWidget::class);

            if (! $isChart && ! is_subclass_of($class, StatsOverviewWidget::class)) {
                return ["{$class}: neither a stats nor a chart widget — not supported on mobile yet."];
            }
        } catch (Throwable $e) {
            return ["{$class}: could not be loaded: " . $e->getMessage()];
        }

        try {
            $widget = new $class();
        } catch (Throwable $e) {
            return ["{$class}: could not be constructed: " . $e->getMessage()];
        }

        if ($mountError = $this->tryMount($widget)) {
            return ["{$class}: mount() threw: " . $mountError->getMessage()];
        }

        // The same normalisation read() runs, not just "getData() doesn't
        // throw": a dataset the endpoint would silently drop in production
        // is exactly what doctor exists to name. A scratch reader keeps the
        // captured warnings out of the caller's WalkWarnings.
        $scratch = new self($captured = new WalkWarnings());
        $node = $isChart ? $scratch->chart($widget, $class) : $scratch->stats($widget, $class);

        $problems = [];

        if ($node === null && $captured->isEmpty()) {
            $problems[] = "{$class}: produced no readable node.";
        }

        foreach ($captured->all() as $warning) {
            $problems[] = "{$class}: " . $warning['reason'];
        }

        return $problems;
    }

    private function isSupported(string $class): bool
    {
        // Same guard as problems(): class_exists() can throw mid-autoload,
        // and one broken widget file must not 500 the whole dashboard.
        try {
            return class_exists($class)
                && (is_subclass_of($class, StatsOverviewWidget::class)
                    || is_subclass_of($class, ChartWidget::class));
        } catch (Throwable $e) {
            $this->warn($class, 'could not be loaded: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stats(object $widget, string $class): ?array
    {
        $stats = $this->protectedCall($widget, 'getStats', $class);

        if (! is_array($stats)) {
            return null;
        }

        $nodes = [];

        foreach ($stats as $stat) {
            if (! is_object($stat)) {
                continue;
            }

            $nodes[] = [
                'label' => (string) $this->text(
                    $this->evaluator->value(
                        fn () => $stat->getLabel(), null, $class, 'stat', 'label',
                    ),
                    $class,
                    'stat label',
                ),
                // Stringified once, deliberately: getValue() is `mixed` and a
                // panel returns ints, floats and already-formatted money. The
                // phone cannot know the panel's formatting intent, so it must
                // not re-format — see the spec.
                'value' => $this->stringify(
                    $this->evaluator->value(
                        fn () => $stat->getValue(), null, $class, 'stat', 'value',
                    ),
                    $class,
                    'stat value',
                ),
                'description' => $this->text(
                    $this->evaluator->value(
                        fn () => $stat->getDescription(), null, $class, 'stat', 'description',
                    ),
                    $class,
                    'stat description',
                ),
                'descriptionIcon' => $this->text(
                    $this->evaluator->value(
                        fn () => $stat->getDescriptionIcon(), null, $class, 'stat', 'descriptionIcon',
                    ),
                    $class,
                    'stat descriptionIcon',
                ),
                'color' => $this->semantic(
                    $this->evaluator->value(
                        fn () => $stat->getColor(), null, $class, 'stat', 'color',
                    ),
                ),
                'chart' => $this->numbers(
                    $this->evaluator->value(
                        fn () => $stat->getChart(), null, $class, 'stat', 'chart',
                    ),
                ),
            ];
        }

        return [
            'type' => 'stats',
            // getHeading()/getDescription() are protected on
            // StatsOverviewWidget (unlike ChartWidget's public pair), and the
            // web widget renders both — so must mobile.
            'heading' => $this->text(
                $this->protectedCall($widget, 'getHeading', $class),
                $class,
                'stats heading',
            ),
            'description' => $this->text(
                $this->protectedCall($widget, 'getDescription', $class),
                $class,
                'stats description',
            ),
            'stats' => $nodes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chart(object $widget, string $class): ?array
    {
        // Through getCachedData(), never getData() directly: mount() already
        // ran the queries once while computing the data checksum and memoized
        // the result — re-reading getData() would run every query twice per
        // request. The memo is also what the web panel actually renders.
        // Guarded, in case a Filament leg on the version matrix loses it.
        $data = $this->protectedCall(
            $widget,
            method_exists($widget, 'getCachedData') ? 'getCachedData' : 'getData',
            $class,
        );
        $type = $this->protectedCall($widget, 'getType', $class);

        if (! is_array($data) || ! is_string($type)) {
            return null;
        }

        $datasets = [];

        foreach ($data['datasets'] ?? [] as $dataset) {
            $values = is_array($dataset) ? $this->numbers($dataset['data'] ?? null) : null;

            if ($values === null) {
                // Dropped, never silently: this is a real bug in the panel,
                // and the phone has nothing it could honestly draw.
                $this->warn($class, 'a dataset has no numeric `data` and was dropped.');

                continue;
            }

            $datasets[] = [
                'label' => is_string($dataset['label'] ?? null) ? $dataset['label'] : null,
                'data' => $values,
            ];
        }

        return [
            'type' => 'chart',
            'chartType' => $type,
            'heading' => $this->text(
                $this->evaluator->value(
                    fn () => $widget->getHeading(), null, $class, 'chart', 'heading',
                ),
                $class,
                'chart heading',
            ),
            'description' => $this->text(
                $this->evaluator->value(
                    fn () => $widget->getDescription(), null, $class, 'chart', 'description',
                ),
                $class,
                'chart description',
            ),
            'labels' => array_values(array_map(
                fn ($label): string => (string) $this->stringify($label, $class, 'chart label'),
                is_array($data['labels'] ?? null) ? $data['labels'] : [],
            )),
            'datasets' => $datasets,
        ];
    }

    /**
     * Invokes `mount()` if the widget defines one — `ChartWidget::mount()` is
     * a real Livewire lifecycle hook (it seeds `$filter`, `$dataChecksum`),
     * and plain `new $class()` never calls it. Reflection because a widget
     * may declare it `protected`.
     *
     * Returns the exception on failure rather than throwing or warning
     * itself: `read()` and `problems()` each need to report a mount failure
     * their own way.
     */
    private function tryMount(object $widget): ?Throwable
    {
        if (! method_exists($widget, 'mount')) {
            return null;
        }

        try {
            $reflected = new ReflectionMethod($widget, 'mount');
            $reflected->setAccessible(true);
            $reflected->invoke($widget);

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * Filament keeps a widget's data behind `protected`, so this is the only
     * way in. A failure degrades the widget, never the request.
     */
    private function protectedCall(object $widget, string $method, string $class): mixed
    {
        try {
            $reflected = new ReflectionMethod($widget, $method);
            $reflected->setAccessible(true);

            return $reflected->invoke($widget);
        } catch (Throwable $e) {
            $this->warn($class, "{$method}() could not be read: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Only a string travels. An enum sends its backing value; rendered HTML
     * has no meaning on a phone — but an Htmlable is a real value the panel
     * meant to render, so dropping it warns, same discipline as stringify().
     */
    private function text(mixed $value, string $class, string $context): ?string
    {
        if ($value instanceof Htmlable) {
            $this->warn($class, "a {$context} renders HTML, which a phone cannot display, and was dropped.");

            return null;
        }

        return match (true) {
            is_string($value) && $value !== '' => $value,
            $value instanceof \BackedEnum => (string) $value->value,
            default => null,
        };
    }

    /** An array colour is a custom palette with no name to send. */
    private function semantic(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * A value object that defines `__toString()` has told us exactly how it
     * wants to be rendered — a money type, a `Stringable`, an enum case that
     * implements it — and the panel's formatting is the product (see the
     * class docblock). Only a value with no renderable string form at all is
     * dropped, and that drop is never silent: it is a real value the panel
     * meant to send, so it warns rather than just vanishing into `""`.
     */
    private function stringify(mixed $value, string $class, string $context): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) || is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            $value === null => null,
            $value instanceof Htmlable => null,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \Stringable => (string) $value,
            default => $this->unrenderable($value, $class, $context),
        };
    }

    /** @param string $context what to call the dropped value in the warning — e.g. "stat value", "chart label" */
    private function unrenderable(mixed $value, string $class, string $context): ?string
    {
        $this->warn(
            $class,
            "a {$context} of type " . get_debug_type($value) . ' has no renderable string form and was dropped.',
        );

        return null;
    }

    /**
     * Numeric strings count: MySQL PDO returns strings for DECIMAL
     * aggregates — `SUM(total)` on a money column — and Chart.js renders
     * them on the web, so mobile must not drop the dataset. An empty array
     * is a normal state (zero rows this period), not a refusal — and it is
     * what Dart's parser produces for the same payload.
     *
     * @return list<float|int>|null  null when this is not a list of numbers
     */
    private function numbers(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $numbers = [];

        foreach ($value as $item) {
            if (is_int($item) || is_float($item)) {
                $numbers[] = $item;
            } elseif (is_string($item) && is_numeric($item)) {
                $numbers[] = (float) $item;
            } else {
                return null;
            }
        }

        return $numbers;
    }

    private function warn(string $class, string $reason): void
    {
        $this->warnings->add('dashboard', class_basename($class), $reason);
    }
}
