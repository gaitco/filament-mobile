<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Actions;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\HeadlessTableHost;
use Gait\FilamentMobile\MobileResource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The one place that turns "the resource named 'approve'" into a runnable,
 * record-bound Filament action — and the one place that refuses.
 *
 * Both the payload and the endpoint go through `resolve()`, so the list a
 * client is shown and the list the server will run cannot drift apart. The
 * published list is a hint; `resolve()` is the gate, re-answered on every
 * POST against the record as it stands at that moment.
 */
final class ActionResolver
{
    /** @var array<string, Action>|null */
    private ?array $tableActions = null;

    /** @var list<string> */
    private array $problems = [];

    /** @param class-string $resourceClass */
    public function __construct(
        private readonly string $resourceClass,
        private readonly MobileResource $mobile,
    ) {}

    /**
     * Every opted-in action this record may run, in declaration order.
     *
     * @return array<string, Action>
     */
    public function available(Model $record): array
    {
        $available = [];

        foreach ($this->mobile->getActions() as $name) {
            $action = $this->resolve($name, $record);

            if ($action !== null) {
                $available[$name] = $action;
            }
        }

        return $available;
    }

    /**
     * The gate. Null means "not runnable from mobile", for any reason, and
     * the endpoint turns that into a 403 without distinguishing between them
     * — an unopted action and an unauthorized one must look identical from
     * outside, exactly as show() refuses to distinguish 404 from 403.
     */
    public function resolve(string $name, Model $record): ?Action
    {
        if (! in_array($name, $this->mobile->getActions(), true)) {
            return null;
        }

        $action = $this->tableActions()[$name] ?? null;

        if ($action === null || $this->carriesForm($action)) {
            return null;
        }

        // A fresh bind per call: an Action is a shared object on the table,
        // and leaving one bound to a previous record would answer the next
        // request's gates against the wrong row.
        $bound = clone $action;
        $bound->record($record);

        // Every gate fails closed, the rule this package applies to every
        // closure it evaluates: one that throws refuses its own action and
        // leaves the rest of the request alone.
        try {
            if ($bound->isHidden() || ! $bound->isAuthorized()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $bound;
    }

    /**
     * @return array{name: string, label: string, color: string|null, icon: string|null, confirmation: array{heading: string, description: string|null, submit: string, cancel: string}|null}
     */
    public function serialise(Action $action): array
    {
        return [
            'name' => (string) $action->getName(),
            'label' => $this->label($action),
            'color' => $this->color($action),
            'icon' => $this->icon($action),
            'confirmation' => $this->confirmation($action),
        ];
    }

    /**
     * Cosmetic, not a gate: a throwing label closure means this package
     * cannot NAME the action, not that the user may not run it. Degrading to
     * the action's own machine name keeps the button live rather than
     * costing the whole record its actions — or the whole record its 200.
     *
     * The same floor covers an Htmlable (or missing) label: text() answers
     * null for one, and `(string) null` is `''` — still a String on the
     * wire, so the client's non-String fallback never fires and the screen
     * would render a blank tappable button. Empty degrades to the name too.
     */
    private function label(Action $action): string
    {
        try {
            $label = $this->text($action->getLabel());

            return $label === null || $label === ''
                ? (string) $action->getName()
                : $label;
        } catch (Throwable) {
            return (string) $action->getName();
        }
    }

    /**
     * @return string|null
     */
    private function color(Action $action): ?string
    {
        try {
            // An array colour is a custom palette, which has no name to
            // send; the client's semantic vocabulary is names only.
            return is_string($color = $action->getColor()) ? $color : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function icon(Action $action): ?string
    {
        try {
            return $this->text($action->getIcon());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Configuration problems, for `filament-mobile:doctor`. Never a runtime
     * concern: a broken declaration costs its own action and nothing else.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $this->tableActions();
        $problems = $this->problems;

        foreach ($this->mobile->getActions() as $name) {
            $action = $this->tableActions()[$name] ?? null;

            if ($action === null) {
                $problems[] = "{$name}: no such action on the resource's table().";

                continue;
            }

            if ($this->carriesForm($action)) {
                $problems[] = "{$name}: carries a form — not supported on mobile yet.";
            }
        }

        return array_values($problems);
    }

    /**
     * An action whose modal schema has components collects input this slice
     * cannot carry. Asked through `getSchema()`, Filament's own accessor, so
     * a schema built by any of the ways an action can declare one answers
     * the same way.
     */
    private function carriesForm(Action $action): bool
    {
        try {
            $schema = $action->getSchema(Schema::make());

            return $schema !== null && $schema->getComponents() !== [];
        } catch (Throwable) {
            // A schema that cannot be built is a schema this package cannot
            // vouch for. Refuse, fail closed.
            return true;
        }
    }

    /**
     * Safety-relevant, unlike label/color/icon above: this block is what
     * tells the client whether to prompt before running. A throw here must
     * never be read as "no confirmation needed" — that would run a
     * destructive action with no prompt. So this fails CLOSED: any throw,
     * anywhere in the block, degrades to a generic confirmation rather than
     * `null`. `submit`/`cancel` come back empty rather than a guessed label,
     * which is the wire's own documented "use my default" — the Flutter
     * confirmation dialog only substitutes its own string when these are
     * empty, never overwrites a real one.
     *
     * @return array{heading: string, description: string|null, submit: string, cancel: string}|null
     */
    private function confirmation(Action $action): ?array
    {
        try {
            if (! $action->isConfirmationRequired()) {
                return null;
            }

            $heading = $this->text($action->getModalHeading());

            return [
                // An Htmlable heading reads as null and would ship `''` — a
                // prompt with no question. Same generic floor as the catch
                // below: still a prompt, never a blank one.
                'heading' => $heading === null || $heading === '' ? 'Are you sure?' : $heading,
                'description' => $this->text($action->getModalDescription()),
                'submit' => (string) $this->text($action->getModalSubmitActionLabel()),
                'cancel' => (string) $this->text($action->getModalCancelActionLabel()),
            ];
        } catch (Throwable) {
            return [
                'heading' => 'Are you sure?',
                'description' => null,
                'submit' => '',
                'cancel' => '',
            ];
        }
    }

    /**
     * Filament's label/icon getters answer `string|BackedEnum|Htmlable|null`.
     * Only a string travels: an enum sends its backing value, and rendered
     * HTML has no meaning on a phone at all.
     */
    private function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof Htmlable => null,
            default => null,
        };
    }

    /**
     * The resource's own table, built headless via `HeadlessTableHost` —
     * same construction `filament-mobile:doctor` uses to read columns. Flat,
     * so an action nested in an ActionGroup resolves by name like any other.
     *
     * @return array<string, Action>
     */
    private function tableActions(): array
    {
        if ($this->tableActions !== null) {
            return $this->tableActions;
        }

        try {
            return $this->tableActions = HeadlessTableHost::flatActionsFor($this->resourceClass);
        } catch (Throwable $e) {
            // A table that cannot be built headless costs this resource its
            // actions and says so — it never costs the record endpoint.
            $this->problems[] = "table(): could not be built outside Livewire, "
                . "actions unavailable: {$e->getMessage()}";

            return $this->tableActions = [];
        }
    }
}
