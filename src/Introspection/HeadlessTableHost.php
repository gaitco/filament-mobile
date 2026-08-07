<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * The one place `Actions/ActionResolver.php` reaches to read a resource's
 * declared table actions — and `Introspection/RelationDiscovery.php` to read
 * a relation manager's declared columns — so that a request-path class never
 * has to import `Filament\Tables\Table` or `TableComponent` itself.
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

    /**
     * A relation manager's declared columns, plus whether its table narrows
     * its own query.
     *
     * The narrowing is read rather than applied, deliberately. Measured
     * outside Livewire, `Table::getQuery()` returns a Builder whose model is
     * NULL and whose SQL is `select * where "status" = ?` — the author's
     * scope survived, the relation binding did not. Two hosts were tried (the
     * unbooted manager itself, and a TableComponent as below) with identical
     * results. So a narrowed relation is refused by the caller rather than
     * served unnarrowed, which would list rows the web panel hides.
     *
     * `$queryScopes` is protected and undocumented, and this package supports
     * `filament/filament: ^4.0|^5.0`. Reflecting a property that has gone
     * away would report "no scopes" and fail OPEN — publishing exactly the
     * relations the refusal exists to withhold. So its absence throws instead,
     * which refuses every relation on the resource and says why.
     * `tests/Unit/RelationDiscoveryTest.php` asserts the property exists, so
     * a Filament upgrade that renames it fails the suite rather than the
     * refusal.
     *
     * @return array{columns: list<array{name: string, label: string}>, narrowed: bool}
     */
    public static function relationTableFor(object $manager): array
    {
        $table = $manager->table(Table::make(new class extends TableComponent {}));

        $columns = [];

        // Filament keys `getColumns()` by column name — verified against this
        // vendor tree, where the keys and `getName()` agree.
        //
        // A label is cosmetic, not a gate: a throwing label closure means this
        // package cannot NAME the column, not that its rows must be withheld.
        // Degrading to the column's own machine name — the same floor
        // `Actions/ActionResolver::label()` already chose for actions, and for
        // the same reason — keeps the relation live rather than costing it its
        // whole listing over one closure. An empty or Htmlable label takes the
        // same floor: `(string) null` is `''`, which would render as a blank
        // heading on the phone.
        foreach ($table->getColumns() as $name => $column) {
            try {
                $label = (string) $column->getLabel();
            } catch (Throwable) {
                $label = '';
            }

            $columns[] = ['name' => $name, 'label' => $label === '' ? (string) $name : $label];
        }

        if (! property_exists($table, 'queryScopes')) {
            throw new RuntimeException(
                'Filament\Tables\Table::$queryScopes no longer exists, so this package '
                . 'can no longer tell a narrowed relation from a plain one. Refusing '
                . 'every relation rather than publishing rows the panel may hide.',
            );
        }

        $scopes = new ReflectionProperty($table, 'queryScopes');
        $scopes->setAccessible(true);

        return ['columns' => $columns, 'narrowed' => $scopes->getValue($table) !== []];
    }
}
