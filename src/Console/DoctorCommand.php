<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Console;

use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Gait\FilamentMobile\Actions\ActionResolver;
use Gait\FilamentMobile\Dashboard\WidgetReader;
use Gait\FilamentMobile\Introspection\ChildComponents;
use Gait\FilamentMobile\Introspection\ComponentTypeMap;
use Gait\FilamentMobile\Introspection\FieldPersistence;
use Gait\FilamentMobile\Introspection\HeadlessTableHost;
use Gait\FilamentMobile\Introspection\MediaFields;
use Gait\FilamentMobile\Introspection\RelationDiscovery;
use Gait\FilamentMobile\Introspection\RichContent;
use Gait\FilamentMobile\Introspection\TagFields;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Gait\MobileCore\WalkWarnings;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
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
        $widgetProblems = $this->widgetProblems();
        $repeaterProblems = $this->repeaterProblems($registry, $builder, $mobile, $panel);
        $relationRefusals = $this->relationRefusals($registry);
        $proseCards = $this->proseOnlyCardFields($registry, $mobile, $panel);
        $mediaProblems = $this->mediaProblems($mobile, $builder);
        $tagProblems = $this->tagProblems($mobile, $builder);
        $translatableProblems = $this->translatableProblems($mobile, $builder);
        $reorderProblems = $this->reorderProblems($mobile);
        $labelProblems = $this->labelProblems($mobile, $builder);

        $this->exposure($registry, $mobile, $panel);
        $this->section('Unsupported components', [...$unsupported, ...$this->pasteLines($unsupported)]);
        $this->section('Drift between mobile() and table()', [...$drift['actionable'], ...$drift['ignored']]);
        $this->section('Unresolvable card field paths', $unresolvable);
        $this->section('Actions', $actionProblems);
        $this->section('Widgets', $widgetProblems);
        $this->section('Repeaters', $repeaterProblems);
        $this->section('Relations', $relationRefusals);
        $this->section('Rich text on cards', $proseCards);
        $this->section('Medialibrary', $mediaProblems);
        $this->section('Spatie tags', $tagProblems);
        $this->section('Translatable', $translatableProblems);

        if ($reorderProblems !== []) {
            $this->section('Reordering', $reorderProblems);
        }

        if ($labelProblems !== []) {
            $this->section('Labels', $labelProblems);
        }

        // A resource nobody could walk is a hole in the gate, not a clean bill
        // of health: CI reads the exit code, not the prose above it.
        $actionable = count($unsupported) + count($drift['actionable'])
            + count($actionProblems) + count($widgetProblems) + count($this->skipped($registry, $mobile, $panel));

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

    /**
     * Section 6. Configured dashboard widgets that cannot be read at all: no
     * such class, not a widget, an unsupported kind (only stats/chart ship
     * this slice), or one whose construction/mount/data read throws.
     * Actionable, like Actions above — a broken widget declaration is
     * silently absent from the dashboard payload, exactly the failure this
     * command exists to make loud.
     *
     * `WidgetReader::problems()` deliberately never calls `canView()` — a
     * widget correctly hidden from this run's user is the system working,
     * not a misconfiguration — so this section carries no policy-gated
     * "skipped" caveat the way the resource sections above do.
     *
     * @return list<string>
     */
    private function widgetProblems(): array
    {
        $reader = new WidgetReader(new WalkWarnings());
        $lines = [];

        foreach (config('filament-mobile.widgets', []) as $class) {
            // The controller silently skips a non-string entry; doctor's job
            // is to make dead config loud — and report it, not TypeError out.
            if (! is_string($class)) {
                $lines[] = var_export($class, true) . ' in filament-mobile.widgets is not a class-string.';

                continue;
            }

            $lines = [...$lines, ...$reader->problems($class)];
        }

        return $lines;
    }

    /**
     * Section 7. Three repeater shapes this slice legitimately cannot
     * support — a panel legitimately has these, and the slice simply does
     * not support them (design spec, "Known weaknesses, stated now"):
     *
     *  - a repeater containing a `live()` field: the item template is
     *    static, so that field's row will not re-settle.
     *  - a nested repeater: a repeater inside another repeater's item
     *    template — two levels of row coordinate is a different problem.
     *  - a repeater one of whose children would not round-trip — a
     *    `Hidden`, an unmapped type, a `disabled()`/undehydrated field. The
     *    row array is written whole, so a child with no rule has its key
     *    DELETED from every row on save; the field is published read-only
     *    instead, and this is the only place a panel author can learn which
     *    child cost them the control.
     *
     * A `->relationship()` repeater is NOT in this list since P9: its rows
     * write through the relation pass, so it is published editable. The one
     * relationship shape still reported is the gate that cannot answer — a
     * throwing `relationship()` closure, which stays refused, fail closed.
     *
     * Informational: a panel author is not
     * surprised, but nothing here is wrong with the panel's declaration,
     * only with this slice's support for it. Never folded into $actionable
     * in handle().
     *
     * `live` and nesting are read off the already-built `$panel` document —
     * both are published, through the SAME guarded reads SchemaWalker uses.
     * The relationship-gate and round-trip findings are read off the
     * components, because `/schema` publishes only the verdict
     * (`config.readOnly`) and never the reason, and a finding that cannot
     * name the offending child (or the gate's own message) is not worth
     * printing.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @param  array<string, mixed>  $panel
     * @return list<string>
     */
    private function repeaterProblems(ResourceRegistry $registry, PanelSchemaBuilder $builder, array $mobile, array $panel): array
    {
        $lines = [];
        $byKey = [];

        foreach ($panel['resources'] ?? [] as $resource) {
            if (is_array($resource) && is_string($resource['key'] ?? null)) {
                $byKey[$resource['key']] = $resource;
            }
        }

        foreach (array_keys($mobile) as $class) {
            $document = $byKey[$registry->keyFor($class)] ?? null;

            // Absent means viewAny denied this run — the same "not inspected"
            // case skipped() reports elsewhere; nothing was walked, so there
            // is nothing to check here either.
            if ($document === null) {
                continue;
            }

            $short = class_basename($class);

            $this->walkRepeaterNodes($document['form'] ?? [], $short, false, $lines);
            $this->refusedRepeaters($builder->schemaComponents($class, 'form'), $short, $lines);
        }

        return $lines;
    }

    /**
     * The two refusals only the components can explain: a relationship
     * repeater whose gate cannot answer, and one whose item template holds a
     * child that would not round-trip.
     *
     * A RESOLVABLE relationship repeater is no longer a finding: since P9 its
     * rows write through the relation pass (Filament's own
     * Repeater::saveToRelationship()), so it is published editable and saved.
     * What remains reportable is the gate that THROWS — published read-only,
     * refused by the write path, and otherwise silent.
     *
     * A repeater the write path refuses outright (`disabled()`, never
     * dehydrated) is skipped for the child finding — every child of a
     * disabled container reads as disabled too, so it would report a child
     * as the cause when the container is. It is still reported when its
     * relationship gate cannot answer, which is refused for that reason
     * first.
     *
     * @param  iterable<mixed>  $components
     * @param  list<string>  $lines
     */
    private function refusedRepeaters(iterable $components, string $resourceName, array &$lines): void
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            if (ComponentTypeMap::for($component) === 'repeater') {
                $label = $resourceName . '.' . ($this->componentName($component) ?? '?');

                if (FieldPersistence::refusesRelationship($component, $error) && $error !== null) {
                    $lines[] = $label . ': its relationship gate cannot answer (' . $error->getMessage() . '), so the repeater is published read-only — a gate that cannot answer refuses';
                } elseif (! FieldPersistence::refuses($component)) {
                    $withheld = RuleExtractor::withheldChild(ChildComponents::of($component));

                    if ($withheld !== null) {
                        $lines[] = $label . ': child `' . $withheld . '` would not round-trip (hidden, unmapped, disabled or never dehydrated), so the whole repeater is published read-only — writing the array would delete that key from every row';
                    }
                }
            }

            $this->refusedRepeaters(ChildComponents::of($component), $resourceName, $lines);
        }
    }

    private function componentName(object $component): ?string
    {
        if (! method_exists($component, 'getName')) {
            return null;
        }

        try {
            $name = $component->getName();
        } catch (Throwable) {
            return null;
        }

        return is_string($name) ? $name : null;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $lines
     */
    private function walkRepeaterNodes(array $nodes, string $resourceName, bool $insideRepeater, array &$lines): void
    {
        foreach ($nodes as $node) {
            $isRepeater = ($node['type'] ?? null) === 'repeater';
            $label = $resourceName . '.' . ($node['name'] ?? '?');

            if ($isRepeater && $insideRepeater) {
                $lines[] = $label . ': nested repeater — a repeater inside a repeater is unsupported this slice';
            }

            if ($isRepeater && $this->containsLive($node['children'] ?? [])) {
                $lines[] = $label . ': contains a live() field — its row will not re-settle, the item template is static';
            }

            $this->walkRepeaterNodes($node['children'] ?? [], $resourceName, $insideRepeater || $isRepeater, $lines);
        }
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function containsLive(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['live'] ?? false) === true || $this->containsLive($node['children'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Section 8. Relation managers `RelationDiscovery` refused, and why —
     * the only channel that explains why a relation manager the panel
     * declares never reaches `/schema`. Every entry `getRelations()`
     * returns ends up here or in a published relation; nothing is silently
     * dropped. The section's second half (P9) names published relations
     * whose WRITE endpoints are off because the child model resolves to
     * zero or several mobile resources — again the only channel, since
     * /schema expresses the same fact by withholding the `resource` key.
     *
     * Informational, like unresolvableCardPaths() above: a refusal is this
     * package correctly declining to approximate a relation it cannot
     * reproduce outside Livewire, not a defect in the panel's own
     * declaration. Never folded into $actionable in handle().
     *
     * Over `allResourceClasses()`, not `$mobile`: a resource's relations can
     * be refused whether or not the resource itself opted into mobile() —
     * the panel author still benefits from knowing why.
     *
     * One line per refusal, `Resource.Manager: reason` — the same shape
     * every other section in this file uses. Testbench's
     * `expectsOutputToContain()` mocks `doWrite` per line and lets exactly
     * one expectation claim each call, so a test asserting two substrings
     * that both land in one line must do it with a SINGLE compound-substring
     * call (see `DoctorRelationsTest.php`), not two chained calls — the
     * existing `RepeaterProblemResource.rel_rows: Repeater::relationship()`-
     * style assertions elsewhere in `DoctorCommandTest.php` already follow
     * this.
     *
     * @return list<string>
     */
    private function relationRefusals(ResourceRegistry $registry): array
    {
        $lines = [];
        $mobile = $registry->mobileResources();

        foreach ($registry->allResourceClasses() as $class) {
            if (! is_string($class)) {
                continue;
            }

            // The resource's own MobileResource when it has one: a relation's
            // card — and therefore whether it is publishable at all — depends
            // on the host's `relationCard()` declarations, so discovery cannot
            // answer without them. A resource that never opted into mobile()
            // passes null and gets the derived-card answer, which is still the
            // useful one for the panel author.
            foreach (RelationDiscovery::refusalsFor($class, $mobile[$class] ?? null) as $manager => $reason) {
                $lines[] = class_basename($class) . '.' . class_basename($manager) . ': ' . $reason;
            }
        }

        // P9, second half of the section: a PUBLISHED relation whose rows are
        // read-only because the child model resolves to zero or several
        // mobile resources. The relation works — it reads — so it is no
        // refusal; but its write endpoints 404 and /schema carries no
        // `resource` key, and nothing else tells the panel author why the
        // Add/Edit affordances never appear. Scoped to mobile resources: a
        // resource the API does not serve at all has no relations to write
        // through, and listing every one of those would bury the signal.
        //
        // Zero matches is the ordinary case (the child simply is not a mobile
        // resource); several means two forms could own the write and the
        // package refuses to guess. The count is what tells them apart.
        foreach (array_keys($mobile) as $class) {
            foreach (RelationDiscovery::for($class, $mobile[$class], $registry) as $relation) {
                if ($relation['related'] === null || $relation['resource'] !== null) {
                    continue;
                }

                // Reported before the owner count, because it is a different
                // fault with a different fix: the child model may well be
                // served by exactly one resource: it is the relation TYPE that
                // cannot carry a create. Naming the owner count here would
                // send the author looking for a resource that already exists.
                if (! $relation['createsLinked']) {
                    $lines[] = class_basename($class) . '.' . $relation['key']
                        . ': rows are read-only — a create through this relation type would not link the'
                        . ' new row to the parent (only HasOne/HasMany/MorphOne/MorphMany and'
                        . ' BelongsToMany/MorphToMany can), so the write endpoints 404. A'
                        . ' HasManyThrough or HasOneThrough reaches past its intermediate table, and a'
                        . ' BelongsTo/MorphTo would write the PARENT, not a child';

                    continue;
                }

                $count = count($registry->ownersOf($relation['related']));
                $why = $count === 0
                    ? 'no mobile resource serves it'
                    : $count . ' mobile resources serve it, and the write would have to guess between them';

                $lines[] = class_basename($class) . '.' . $relation['key']
                    . ': rows are read-only — the child model ' . class_basename($relation['related'])
                    . ' is not unambiguously one resource (' . $why . '), so the write endpoints 404';
            }
        }

        return $lines;
    }

    /**
     * Section 9. A card slot bound to a column that is rich ONLY because one
     * infolist entry calls `->prose()`.
     *
     * `->prose()` is a declaration about that one entry; `HasRichContent` is a
     * fact about the column. Only the column-level fact can honestly reach a
     * card, which is a different surface no infolist entry governs — and
     * `index()` would have to build and walk the infolist on every list request
     * to know otherwise, a cost it exists to avoid. So such a card slot gets
     * `<path>.__rich` on `show()` and none on `index()`, and the list card
     * renders raw markup.
     *
     * Nothing else tells the panel author why one card slot came out clean and
     * the one beside it did not: `/schema` publishes the card's field paths,
     * not which of them convert. Informational — the
     * declaration is legal, this slice simply cannot honour it on a card — so
     * it is never folded into `$actionable` in handle().
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @param  array<string, mixed>  $panel
     * @return list<string>
     */
    private function proseOnlyCardFields(ResourceRegistry $registry, array $mobile, array $panel): array
    {
        $lines = [];
        $byKey = array_column($panel['resources'] ?? [], null, 'key');

        foreach ($mobile as $class => $resource) {
            // Absent means viewAny denied this run — nothing was walked, so
            // there is nothing to check. Same case skipped() reports.
            $document = $byKey[$registry->keyFor($class)] ?? null;

            if ($document === null) {
                continue;
            }

            $proseOnly = array_diff(
                RichContent::entryNamesIn($document['infolist'] ?? []),
                RichContent::attributesFor($class::getModel()),
            );

            foreach (array_intersect($resource->getCard()->fieldPaths(), $proseOnly) as $path) {
                $lines[] = class_basename($class) . ': card field `' . $path
                    . '` is rich only because the infolist calls ->prose(), so the list endpoint publishes no `'
                    . $path . '.__rich` and the card renders raw markup — register it on '
                    . class_basename($class::getModel()) . ' with HasRichContent to fix it';
            }
        }

        return $lines;
    }

    /**
     * Section 10 (P14). Three medialibrary findings the design spec's
     * "Doctor" section names, none of them actionable — each is a defect in
     * this SLICE's coverage of a legal declaration, the same reasoning
     * unresolvableCardPaths()/proseOnlyCardFields() above already apply:
     *
     *  - a media component (upload OR entry) on a model without `HasMedia`.
     *    A form UPLOAD in that shape already gets an ACTIONABLE line in the
     *    "Unsupported components" section above — SchemaWalker's own
     *    WalkWarnings entry (Task 2), read()'s 'file' type branch. What is
     *    genuinely new here is the INFOLIST entry case: an entry carries no
     *    `config.readOnly` for the walker to gate, so nothing else ever
     *    names why it reads empty.
     *  - a media component whose name collides with a real column: the
     *    serializer's media pass (RecordSerializer::withMediaPaths())
     *    OVERWRITES the raw column read at that same path, almost certainly
     *    an accidental naming collision rather than a deliberate shadow.
     *  - a card slot bound to a media path on a model without `HasMedia`:
     *    the same serializer pass is gated on `method_exists($record,
     *    'getMedia')`, so the slot publishes null on every list/relation
     *    row, forever.
     *
     * Read off the raw components (`schemaComponents()`), the same source
     * repeaterProblems()/refusedRepeaters() above use, not the published
     * document — an infolist entry has no walked "config" shape at all to
     * read this off of.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function mediaProblems(array $mobile, PanelSchemaBuilder $builder): array
    {
        $lines = [];

        foreach ($mobile as $class => $resource) {
            $short = class_basename($class);
            $model = new ($class::getModel())();
            $hasMedia = method_exists($model, 'getMedia');
            $cardPaths = $resource->getCard()->fieldPaths();

            foreach (['form', 'infolist'] as $method) {
                $this->walkMediaComponents(
                    $builder->schemaComponents($class, $method),
                    $short,
                    $model,
                    $hasMedia,
                    $cardPaths,
                    $lines,
                );
            }
        }

        return $lines;
    }

    /**
     * @param  iterable<mixed>  $components
     * @param  list<string>  $cardPaths
     * @param  list<string>  $lines
     */
    private function walkMediaComponents(iterable $components, string $resourceName, Model $model, bool $hasMedia, array $cardPaths, array &$lines): void
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $isUpload = MediaFields::isMediaUpload($component);
            $isEntry = MediaFields::isMediaEntry($component);

            if ($isUpload || $isEntry) {
                $name = $this->componentName($component);
                $kind = $isUpload ? 'SpatieMediaLibraryFileUpload' : 'SpatieMediaLibraryImageEntry';

                if (is_string($name) && $name !== '') {
                    if (! $hasMedia) {
                        $lines[] = $resourceName . '.' . $name . ': ' . $kind
                            . ' on a model without HasMedia — the field always reads empty';

                        if (in_array($name, $cardPaths, true)) {
                            $lines[] = $resourceName . ': card field `' . $name
                                . '` is bound to a media path on a model without HasMedia — the slot will always publish null';
                        }
                    } elseif (in_array($name, $this->columnsOf($model) ?? [], true)) {
                        $lines[] = $resourceName . '.' . $name . ': ' . $kind
                            . ' collides with a real column of the same name — the media value will shadow the column in the payload';
                    }
                }
            }

            $this->walkMediaComponents(ChildComponents::of($component), $resourceName, $model, $hasMedia, $cardPaths, $lines);
        }
    }

    /**
     * P15 Task 4: three Spatie tags findings, the design spec's "Doctor"
     * section, none of them actionable — the same `mediaProblems()`
     * reasoning above. `SpatieTagsInput` is form-only (no infolist entry
     * equivalent the way medialibrary has one), so unlike media's diagnostic
     * (1), the traitless-model finding here has no informational-only
     * fixture: `SchemaWalker::tagsFailClosed()` (Task 2) already drops that
     * exact shape with a WalkWarnings entry, which the pre-existing
     * "Unsupported components" section already folds into `$actionable` —
     * this section's line is additional context on the SAME finding, not a
     * second bug. Diagnostics (2) collision and (3) card-bound path on a
     * traitless model are genuinely new and can be exercised in isolation.
     *
     *  - a Spatie tags component on a model without `HasTags`;
     *  - a Spatie tags component whose name collides with a real column: the
     *    serializer's tags pass (`RecordSerializer::withTagPaths()`)
     *    overwrites the raw column read at that same path;
     *  - a card slot bound to a Spatie tags path on a model without
     *    `HasTags`: the same serializer pass is gated on
     *    `method_exists($record, 'syncTagsWithType')`, so the slot publishes
     *    `[]` on every list/relation row, forever.
     *
     * Read off the raw components (`schemaComponents()`), same source
     * `mediaProblems()` uses — only `form`, since `SpatieTagsInput` never
     * appears in an infolist.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function tagProblems(array $mobile, PanelSchemaBuilder $builder): array
    {
        $lines = [];

        foreach ($mobile as $class => $resource) {
            $short = class_basename($class);
            $model = new ($class::getModel())();
            $hasTags = method_exists($model, 'syncTagsWithType');
            $cardPaths = $resource->getCard()->fieldPaths();

            $this->walkTagComponents(
                $builder->schemaComponents($class, 'form'),
                $short,
                $model,
                $hasTags,
                $cardPaths,
                $lines,
            );
        }

        return $lines;
    }

    /**
     * @param  iterable<mixed>  $components
     * @param  list<string>  $cardPaths
     * @param  list<string>  $lines
     */
    private function walkTagComponents(iterable $components, string $resourceName, Model $model, bool $hasTags, array $cardPaths, array &$lines): void
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            if (TagFields::isSpatieTags($component)) {
                $name = $this->componentName($component);

                if (is_string($name) && $name !== '') {
                    if (! $hasTags) {
                        $lines[] = $resourceName . '.' . $name . ': SpatieTagsInput on a model without HasTags — the field always reads empty';

                        if (in_array($name, $cardPaths, true)) {
                            $lines[] = $resourceName . ': card field `' . $name
                                . '` is bound to a tags path on a model without HasTags — the slot will always publish an empty list';
                        }
                    } elseif (in_array($name, $this->columnsOf($model) ?? [], true)) {
                        $lines[] = $resourceName . '.' . $name . ': SpatieTagsInput collides with a real column of the same name — the tags value will shadow the column in the payload';
                    }
                }
            }

            $this->walkTagComponents(ChildComponents::of($component), $resourceName, $model, $hasTags, $cardPaths, $lines);
        }
    }

    /**
     * P17 Task 2: the one informational Translatable diagnostic — an
     * UNDOTTED form field whose name is one of the model's own real
     * `getTranslatableAttributes()` (behind the same `method_exists` pair
     * `SchemaWalker::translatableAttributesOf()` and
     * `RecordForm::storedPaths()` both gate on, never a
     * `spatie/laravel-translatable` import here). That shape is the official
     * `filament/spatie-laravel-translatable-plugin`'s own convention — one
     * field per attribute, swapped to the CURRENT locale by the plugin — and
     * this package's mobile panel has no locale switcher of its own, so every
     * write to that field travels through whatever locale the request
     * resolves to. Never a WalkWarnings entry: the field is perfectly valid
     * Filament and walks and writes fine, so this is purely context a host
     * should see once, the same informational role `tagProblems()` plays for
     * a column-collision finding.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function translatableProblems(array $mobile, PanelSchemaBuilder $builder): array
    {
        $lines = [];

        foreach (array_keys($mobile) as $class) {
            $short = class_basename($class);
            $model = new ($class::getModel())();

            if (! method_exists($model, 'getTranslations') || ! method_exists($model, 'getTranslatableAttributes')) {
                continue;
            }

            $this->walkTranslatableComponents(
                $builder->schemaComponents($class, 'form'),
                $short,
                $model->getTranslatableAttributes(),
                $lines,
            );
        }

        return $lines;
    }

    /**
     * @param  iterable<mixed>  $components
     * @param  list<string>  $translatable
     * @param  list<string>  $lines
     */
    private function walkTranslatableComponents(iterable $components, string $resourceName, array $translatable, array &$lines): void
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $name = $this->componentName($component);

            if (is_string($name) && $name !== '' && ! str_contains($name, '.') && in_array($name, $translatable, true)) {
                $lines[] = $resourceName . '.' . $name . ": undotted field on a translatable attribute — mobile edits the panel's current locale only for this field";
            }

            $this->walkTranslatableComponents(ChildComponents::of($component), $resourceName, $translatable, $lines);
        }
    }

    /**
     * P18 Task 5: three reordering findings, all informational — a resource
     * declaration is legal, this slice simply does not support it on mobile
     * (pivots), or the declaration's config needs aligning (missing column,
     * Spatie mismatch).
     *
     *  - a reorder column that does not exist on the model's table;
     *  - a reorder column that differs from the model's Spatie sortable
     *    order_column_name when the model implements Sortable;
     *  - a reorderable on a pivot column (dotted).
     *
     * Read the raw column off the table via `HeadlessTableHost`, not
     * `ReorderDeclaration::for()` — the latter returns null for dotted
     * columns, which is exactly what diagnostic (c) must catch.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function reorderProblems(array $mobile): array
    {
        $lines = [];

        foreach ($mobile as $class => $resource) {
            $short = class_basename($class);
            $model = new ($class::getModel())();
            $table = HeadlessTableHost::tableFor($class);
            $column = $table->getReorderColumn();

            // No reorderable declaration — nothing to check.
            if (blank($column)) {
                continue;
            }

            // Diagnostic (c): a reorderable on a pivot column.
            if (str_contains($column, '.')) {
                $lines[] = $short . ': reorderable on a pivot column [' . $column . '] — not offered on mobile';

                continue;
            }

            // Diagnostic (a): column does not exist on the table.
            if (! Schema::hasColumn($model->getTable(), $column)) {
                $lines[] = $short . ': table reorderable on [' . $column . '] but ' . $model->getTable() . ' has no such column';

                continue;
            }

            // Diagnostic (b): model has Spatie sortable with a different column.
            if (method_exists($model, 'determineOrderColumnName')) {
                $sortableColumn = $model->determineOrderColumnName();

                if ($sortableColumn !== $column) {
                    $lines[] = $short . ': table reorders [' . $column . '] but the model\'s Spatie sortable column is [' . $sortableColumn . ']';
                }
            }
        }

        return $lines;
    }

    /**
     * The nudge this slice adds: a dotted leaf (`category.name`) with no
     * `->label()` call publishes Filament's own name-derived default — the
     * LAST segment only (`afterLast('.')`, measured in vendor/filament/
     * schemas/src/Components/Concerns/HasLabel.php's Field/Entry overrides —
     * `getLabel()` itself, so this reads the exact label the client would
     * show, never a re-derivation of it). "Name" for `category.name` reads
     * fine on a desktop grid beside its section heading, but a phone list has
     * no such heading to supply the missing "Category" context.
     *
     * `hasCustomLabel()` (the trait's own accessor — `$this->label !==
     * null`) is the one honest signal: name-sniffing would flag a field whose
     * author DID call `->label('Name')` deliberately, which is not a defect.
     * Informational only, like `tagProblems()`/`mediaProblems()` — a dotted
     * name with a default label still walks and serves fine, so this is
     * never folded into `$actionable`.
     *
     * @param  array<class-string, MobileResource>  $mobile
     * @return list<string>
     */
    private function labelProblems(array $mobile, PanelSchemaBuilder $builder): array
    {
        $lines = [];

        foreach ($mobile as $class => $resource) {
            $short = class_basename($class);

            foreach (['form', 'infolist'] as $method) {
                $this->walkLabelComponents($builder->schemaComponents($class, $method), $short, $lines);
            }
        }

        return $lines;
    }

    /**
     * @param  iterable<mixed>  $components
     * @param  list<string>  $lines
     */
    private function walkLabelComponents(iterable $components, string $resourceName, array &$lines): void
    {
        foreach ($components as $component) {
            if (! is_object($component) || ComponentTypeMap::isSkipped($component)) {
                continue;
            }

            $name = $this->componentName($component);

            if (is_string($name) && str_contains($name, '.') && ! $this->hasCustomLabel($component)) {
                $label = $this->componentLabel($component);

                if ($label !== null) {
                    $lines[] = $resourceName . '.' . $name . ": label defaults to '" . $label
                        . "' — set ->label() to disambiguate on the phone";
                }
            }

            $this->walkLabelComponents(ChildComponents::of($component), $resourceName, $lines);
        }
    }

    /**
     * Fails closed toward SILENCE, not toward the nudge: a component this
     * package cannot ask (no `hasCustomLabel()` at all) or whose evaluation
     * throws answers `true` here — "has a custom label" — so it is never
     * flagged. A false positive on a component this section cannot actually
     * read would be worse than a missed one.
     */
    private function hasCustomLabel(object $component): bool
    {
        if (! method_exists($component, 'hasCustomLabel')) {
            return true;
        }

        try {
            return (bool) $component->hasCustomLabel();
        } catch (Throwable) {
            return true;
        }
    }

    private function componentLabel(object $component): ?string
    {
        if (! method_exists($component, 'getLabel')) {
            return null;
        }

        try {
            $label = $component->getLabel();
        } catch (Throwable) {
            return null;
        }

        return is_string($label) ? $label : null;
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
