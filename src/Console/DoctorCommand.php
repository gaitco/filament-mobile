<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Console;

use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Gait\FilamentMobile\Actions\ActionResolver;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * The condition on which the mobile()/table() duplication was accepted: this
 * command makes the drift visible. Without it, a `sorts()` key that no longer
 * matches a table column is silently rotting config nobody is told about.
 *
 * THE ONE FILE IN src/ ALLOWED TO TOUCH LIVEWIRE AND Filament\Tables\Table.
 * The ban exists to keep the *request* path Livewire-free; a CLI command runs
 * in a full app context with nothing to serve, and an approximate drift check
 * is worth less than none. tests/Unit/ArchitectureTest.php exempts this exact
 * path — do not widen that exemption to any other file.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'filament-mobile:doctor {--user= : Build the panel as this user (id or email), so a policy-guarded panel is actually inspected}';

    protected $description = 'Report mobile panel exposure, unsupported components and mobile()/table() drift.';

    /** @var array<class-string, list<string>|null> model class => column listing */
    private array $columnCache = [];

    public function handle(ResourceRegistry $registry, PanelSchemaBuilder $builder): int
    {
        // Anonymous by default, as before. But an anonymous build in a
        // policy-guarded panel denies every viewAny, so all four sections read
        // `(none)`, nothing is ever inspected, and the run fails forever — the
        // same "a gate that fires on valid config gets switched off" failure
        // this command's drift check was fixed for at the section level.
        $user = $this->resolveUser();

        if ($this->option('user') !== null && $user === null) {
            $this->error('No user matches [' . $this->option('user') . '] on the ' . $this->guard() . ' guard.');

            return self::FAILURE;
        }

        $mobile = $registry->mobileResources();
        $panel = $builder->build($user);

        $unsupported = $this->unsupported($builder);
        $drift = $this->drift($mobile, $unsupported);
        $unresolvable = $this->unresolvableCardPaths($mobile);
        $actionProblems = $this->actionProblems($mobile);

        $this->exposure($registry, $mobile, $panel);
        $this->section('Unsupported components', [...$unsupported, ...$this->pasteLines($unsupported)]);
        $this->section('Drift between mobile() and table()', [...$drift['actionable'], ...$drift['ignored']]);
        $this->section('Unresolvable card field paths', $unresolvable);
        $this->section('Actions', $actionProblems);

        // A resource nobody could walk is a hole in the gate, not a clean bill
        // of health: CI reads the exit code, not the prose above it.
        $actionable = count($unsupported) + count($drift['actionable'])
            + count($actionProblems) + count($this->skipped($registry, $mobile, $panel));

        $this->newLine();
        $this->line($actionable === 0
            ? 'Nothing actionable.'
            : $actionable . ' actionable finding(s).');

        return $actionable === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The identity to build the panel as — `--user=7` or `--user=a@b.test`,
     * resolved through the same guard's provider the endpoints authenticate
     * against, so doctor sees exactly what that user's phone would.
     */
    private function resolveUser(): ?Authenticatable
    {
        $identifier = $this->option('user');

        if ($identifier === null) {
            return null;
        }

        $provider = Auth::createUserProvider(config('auth.guards.' . $this->guard() . '.provider'));

        // Numeric means id, anything else means email. Not both: retrieveById
        // with a non-numeric string is a type error on an integer key column
        // in MySQL's strict mode.
        return is_numeric($identifier)
            ? $provider?->retrieveById($identifier)
            : $provider?->retrieveByCredentials(['email' => $identifier]);
    }

    private function guard(): string
    {
        return config('filament-mobile.guard') ?? config('auth.defaults.guard');
    }

    /**
     * Section 1. Which resources the API serves, and which the panel holds but
     * mobile cannot see. A resource without mobile() is opt-in working as
     * designed, so it is reported without failing the run.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @param  array<string, mixed>  $panel
     */
    private function exposure(ResourceRegistry $registry, array $mobile, array $panel): void
    {
        $this->line('Exposed resources');

        foreach (array_keys($mobile) as $class) {
            $this->line('  ' . $registry->keyFor($class) . '  (' . class_basename($class) . ')');
        }

        if ($mobile === []) {
            $this->line('  (none — no resource declares mobile())');
        }

        $invisible = [];

        foreach ($registry->allResourceClasses() as $class) {
            if (is_string($class) && ! array_key_exists($class, $mobile)) {
                $invisible[] = class_basename($class);
            }
        }

        if ($invisible !== []) {
            $this->line('Not exposed — no mobile() declared');

            foreach ($invisible as $name) {
                $this->line('  ' . $name);
            }
        }

        $skipped = $this->skipped($registry, $mobile, $panel);

        if ($skipped !== []) {
            $this->line('Not inspected — viewAny denies this run');

            foreach ($skipped as $key) {
                $this->line('  ' . $key);
            }

            if ($this->option('user') === null) {
                $this->line('  → run again with --user=<id|email> to inspect a policy-guarded panel');
            }
        }
    }

    /**
     * Mobile resources the builder never walked, because their `viewAny`
     * denies the anonymous user a console run has. Their components were not
     * inspected at all, so reporting them clean would be a lie.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @param  array<string, mixed>  $panel
     * @return list<string>
     */
    private function skipped(ResourceRegistry $registry, array $mobile, array $panel): array
    {
        return array_values(array_diff(
            array_map($registry->keyFor(...), array_keys($mobile)),
            array_column($panel['resources'] ?? [], 'key'),
        ));
    }

    /**
     * Section 2. Everything the schema walker could not handle, plus any
     * table() that could not be built here — a table that needs its Livewire
     * host is a table this command cannot check for drift, which is the same
     * class of blind spot.
     *
     * @return list<string>
     */
    private function unsupported(PanelSchemaBuilder $builder): array
    {
        $lines = [];

        foreach ($builder->warnings()->all() as $warning) {
            $lines[] = $warning['resource'] . '.' . $warning['component'] . ': ' . $warning['reason'];
        }

        return $lines;
    }

    /**
     * Display-only: for each component `unsupported()` named, the exact
     * `config/filament-mobile.php` line to paste so a host never has to go
     * spelunking for the FQCN and the array syntax. Grouped by class, not by
     * warning, and never folded into `$unsupported` itself — that array's
     * count drives the exit code, and a paste hint is not a new finding.
     *
     * @param  list<string>  $unsupported
     * @return list<string>
     */
    private function pasteLines(array $unsupported): array
    {
        $prefix = ': unsupported component type ';
        $counts = [];

        foreach ($unsupported as $line) {
            $position = strrpos($line, $prefix);

            if ($position === false) {
                continue;
            }

            $class = substr($line, $position + strlen($prefix));
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }

        $lines = [];

        foreach ($counts as $class => $count) {
            ['type' => $type, 'guessed' => $guessed] = $this->guessType($class);
            $uses = $count === 1 ? '1 use' : $count . ' uses';
            $comment = $guessed ? '  // guess — pick the right type for this field' : '';

            $lines[] = '';
            $lines[] = 'Unmapped: ' . $class . '  (' . $uses . ')';
            $lines[] = '  add to config/filament-mobile.php:';
            $lines[] = "    \\{$class}::class => '{$type}',{$comment}";
        }

        return $lines;
    }

    /**
     * A type only where the class name makes one obvious — "…Picker" reads as
     * a choice control, "…Input" as a text-shaped one. Anything else defaults
     * to `text` flagged as a guess, since a wrong-but-visible field beats one
     * silently dropped.
     *
     * @return array{type: string, guessed: bool}
     */
    private function guessType(string $class): array
    {
        $basename = class_basename($class);

        return match (true) {
            str_contains($basename, 'Picker') => ['type' => 'select', 'guessed' => false],
            str_contains($basename, 'Input') => ['type' => 'text', 'guessed' => false],
            default => ['type' => 'text', 'guessed' => true],
        };
    }

    /**
     * Section 3. `sorts()`/`searchable()` keys that name nothing on table()
     * (actionable — the mobile client offers a control the backend cannot
     * honour), and table columns mobile() ignores (informational — a card
     * showing less than a table is the normal case, not a defect).
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @param  list<string>  $unsupported
     * @return array{actionable: list<string>, ignored: list<string>}
     */
    private function drift(array $mobile, array &$unsupported): array
    {
        $actionable = [];
        $ignored = [];

        foreach ($mobile as $class => $resource) {
            $short = class_basename($class);

            try {
                $columns = array_keys($class::table(Table::make($this->tableHost()))->getColumns());
            } catch (Throwable $e) {
                $unsupported[] = $short . '.table(): could not be built outside Livewire, drift unchecked: ' . $e->getMessage();

                continue;
            }

            // No columns means no table() worth comparing against — there is
            // no drift between a declaration and an absent one.
            if ($columns === []) {
                continue;
            }

            $declared = [...array_keys($resource->getSorts()), ...$resource->getSearchable()];
            $model = new ($class::getModel())();

            foreach (array_unique($declared) as $key) {
                // A sort or search key is spent on the *database* at runtime
                // (`$query->orderBy($key)`), not on the table. Sorting by a
                // column the table happens not to display is ordinary and
                // correct, so only a key that names nothing at all — neither a
                // table column nor anything on the model — is drift. A gate
                // that fires on valid config gets switched off, and then the
                // drift it exists to catch goes unwatched.
                if (! in_array($key, $columns, true) && ! $this->resolves($model, $key)) {
                    $actionable[] = $short . ': `' . $key . '` is declared in mobile() but names neither a column on table() nor an attribute on ' . class_basename($model);
                }
            }

            $used = [...$declared, ...$resource->getCard()->fieldPaths()];

            foreach (array_diff($columns, $used) as $column) {
                $ignored[] = $short . ': table column `' . $column . '` is not used by mobile() (note)';
            }
        }

        return ['actionable' => $actionable, 'ignored' => $ignored];
    }

    /**
     * Section 4. A card field whose first segment is neither a relation nor an
     * attribute serialises as null forever, silently. Informational: an
     * unreadable schema is a guess, never a hard failure.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function unresolvableCardPaths(array $mobile): array
    {
        $lines = [];

        foreach ($mobile as $class => $resource) {
            $model = new ($class::getModel())();

            foreach ($resource->getCard()->fieldPaths() as $path) {
                $segment = explode('.', $path)[0];

                if ($this->resolves($model, $segment)) {
                    continue;
                }

                $lines[] = class_basename($class) . ': `' . $path . '` — `' . $segment
                    . '` is not a relation or attribute on ' . class_basename($model);
            }
        }

        return $lines;
    }

    /**
     * Section 5. Action declarations that will never reach a phone: a name
     * that resolves to no table action (typo) or one whose action carries a
     * form (unsupported this slice). Actionable, not ignorable — either way
     * the action is silently absent from the payload, which is exactly the
     * failure mode this command exists to make loud.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function actionProblems(array $mobile): array
    {
        $lines = [];

        foreach ($mobile as $class => $resource) {
            foreach ((new ActionResolver($class, $resource))->problems() as $problem) {
                $lines[] = class_basename($class) . '.' . $problem;
            }
        }

        return $lines;
    }

    private function resolves(Model $model, string $segment): bool
    {
        $columns = $this->columnsOf($model);

        // Null means the schema could not be read at all. Every plain
        // attribute would then look unresolvable, so nothing is reported —
        // silence beats a page of false positives.
        return $columns === null
            || $model->isRelation($segment)
            || array_key_exists($segment, $model->getCasts())
            || method_exists($model, 'get' . Str::studly($segment) . 'Attribute')
            || in_array($segment, $columns, true);
    }

    /** @return list<string>|null null when there is no reachable schema to ask */
    private function columnsOf(Model $model): ?array
    {
        return $this->columnCache[$model::class] ??= (function () use ($model): ?array {
            try {
                return $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            } catch (Throwable) {
                return null;
            }
        })();
    }

    /**
     * A Table cannot exist without a HasTable host — that host is the 46-method
     * interface this package refuses to implement on the request path.
     * TableComponent is Filament's own Livewire implementation of it, and one
     * unmounted instance is enough to read declared columns.
     */
    private function tableHost(): TableComponent
    {
        static $host = null;

        return $host ??= new class extends TableComponent {};
    }

    /** @param list<string> $lines */
    private function section(string $title, array $lines): void
    {
        $this->newLine();
        $this->line($title);

        if ($lines === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($lines as $line) {
            $this->line('  ' . $line);
        }
    }
}
