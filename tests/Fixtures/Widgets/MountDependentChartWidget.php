<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * `getData()` reads state that only `mount()` sets. Plain `new $class()`
 * leaves `$seed` at its default; only actually invoking `mount()` flips it.
 * This is what fails if `WidgetReader` ever goes back to constructing a
 * widget without mounting it.
 */
class MountDependentChartWidget extends ChartWidget
{
    protected string $seed = 'unmounted';

    protected function getType(): string
    {
        return 'line';
    }

    public function mount(): void
    {
        // Before parent::mount(): the parent checksums — and thereby
        // memoizes — getData(), exactly as it does on the web, so state must
        // be seeded first there too. Skipping mount() entirely still leaves
        // `$seed` at 'unmounted', which is what this fixture guards against.
        $this->seed = 'mounted';

        parent::mount();
    }

    protected function getData(): array
    {
        return [
            'labels' => [$this->seed],
            'datasets' => [
                ['label' => $this->seed, 'data' => [1]],
            ],
        ];
    }
}
