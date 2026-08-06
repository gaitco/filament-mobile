<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;

/**
 * The one place `Actions/ActionResolver.php` reaches to read a resource's
 * declared table actions, so that a request-path class never has to import
 * `Filament\Tables\Table` or `TableComponent` itself.
 *
 * A `Table` cannot exist without a `HasTable` host — the same 46-method
 * interface `Console/DoctorCommand.php` builds its own headless instance of,
 * for the same reason: reading a resource's `table()` outside Livewire.
 * `TableComponent` is Filament's own Livewire implementation of that
 * interface, and one unmounted, never-hydrated instance is enough to read
 * declared columns and actions off it.
 *
 * Nothing here renders, mounts or dispatches. It is one of exactly three
 * files in `src/` permitted to reference Livewire, enforced by
 * `tests/Unit/ArchitectureTest.php` — alongside `Console/DoctorCommand.php`
 * and `Introspection/HeadlessSchemaHost.php`.
 *
 * A fresh host per call, not a shared static: unlike `DoctorCommand`'s
 * CLI-only use, `ActionResolver` runs on a live request, and a
 * worker-lifetime static (Octane, Swoole) is a question nobody needs to
 * answer for the cost of one throwaway object.
 */
final class HeadlessTableHost
{
    /**
     * @param  class-string  $resourceClass
     * @return array<string, Action>
     */
    public static function flatActionsFor(string $resourceClass): array
    {
        $table = $resourceClass::table(Table::make(new class extends TableComponent {}));

        return $table->getFlatActions();
    }
}
