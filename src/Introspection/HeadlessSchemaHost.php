<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

/**
 * The one place in this package that touches Livewire on a request path.
 *
 * Reading a schema's static shape needs no host — that is why the read path
 * gets away with `Schema::make(null)`. Re-evaluating a reactive closure does:
 * `Filament\Schemas\Components\Utilities\Get::__invoke()` calls
 * `$this->component->getLivewire()` on its first line and resolves every state
 * lookup against that object, so `visible(fn (Get $get) => ...)` cannot be
 * evaluated against submitted values without a real `HasSchemas` component.
 *
 * `InteractsWithSchemas` implements all six of `HasSchemas`' methods, but it
 * also uses `WithFileUploads` and calls `parent::validate()`, `dispatch()` and
 * `skipRender()` — Livewire\Component members. Hence the `extends`.
 *
 * Nothing renders, mounts or hydrates this component; it is a state bag with
 * the right shape. It is one of exactly three files in `src/` permitted to
 * reference Livewire, enforced by `tests/Unit/ArchitectureTest.php` —
 * alongside `Console/DoctorCommand.php` and
 * `Introspection/HeadlessTableHost.php`.
 */
// `AllowDynamicProperties` is for the deprecation notice only. State is seeded
// as properties named after each field's state path, which cannot be declared
// ahead of time. PHP creates them either way and `data_get()` reads them either
// way — but without the opt-in every seeded field emits E_DEPRECATED, which an
// app with a strict error handler escalates to an exception.
#[\AllowDynamicProperties]
class HeadlessSchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /**
     * Seed submitted state so `Get` resolves against it.
     *
     * Filament addresses state as properties on the Livewire component keyed
     * by each field's state path (`data_get($livewire, $statePath)`), so a
     * top-level schema's state is top-level properties here.
     *
     * @param  array<string, mixed>  $state
     */
    public function setMobileState(array $state): void
    {
        foreach ($state as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
