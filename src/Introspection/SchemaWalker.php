<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Error;
use Throwable;

/**
 * Turns a Filament Schema component tree into the contract's component array
 * (design spec §5.3).
 *
 * Obtains nothing itself — it is handed already-built components, so it stays
 * free of any Livewire dependency and is trivially unit-testable.
 */
final class SchemaWalker
{
    private readonly SafeEvaluator $evaluator;

    public function __construct(private readonly WalkWarnings $warnings)
    {
        $this->evaluator = new SafeEvaluator($warnings);
    }

    /**
     * `$resourceKey` is the registry key (`ResourceRegistry::keyFor()`), never
     * derived from `$resource` here — `$resource` is a class basename used
     * only as the warnings label, and the two are not the same string for a
     * resource whose Filament slug differs from its class name. A select's
     * `optionsUrl` is a route the client posts back to, so it must carry the
     * key the route actually resolves by, not a human-readable guess.
     *
     * Defaults to `$resource` only so unit tests that walk a bare component
     * list to inspect node shape — never a route — need not invent a key.
     * Every real endpoint (PanelSchemaBuilder, StateController,
     * MobilePanelController) already holds the registry and passes the true
     * key explicitly.
     *
     * @param  iterable<object>  $components
     * @return list<array<string, mixed>>
     */
    public function walk(iterable $components, string $resource, ?string $resourceKey = null): array
    {
        $resourceKey ??= $resource;
        $nodes = [];

        foreach ($components as $component) {
            if (ComponentTypeMap::isSkipped($component)) {
                continue;
            }

            $type = ComponentTypeMap::for($component);

            if ($type === null) {
                $this->warnings->add(
                    $resource,
                    $this->nameOf($component, $resource) ?? $component::class,
                    'unsupported component type ' . $component::class,
                );

                continue;
            }

            try {
                $nodes[] = $this->node($component, $type, $resource, $resourceKey);
            } catch (Throwable $e) {
                // SafeEvaluator guards each *accessor call* and
                // PanelSchemaBuilder guards schema *construction*, but until
                // this catch nothing guarded what the walker does with the
                // values in between — refineType(), rules(), config(). A
                // grouped `Select::options(['Egypt' => ['cai' => 'Cairo']])`
                // hit `(string) $label` on the nested array and turned one
                // ordinary component into a 500 for the entire /schema
                // document. This is the root guard, not the cast fix: any
                // future value-handling bug degrades one component the same
                // way an unsupported type does.
                $this->warnings->add(
                    $resource,
                    $this->nameOf($component, $resource) ?? $component::class,
                    'could not be converted to a contract node: ' . $e->getMessage(),
                );
            }
        }

        return $nodes;
    }

    /**
     * `hidden` and `disabled` are the only two properties the client treats as
     * permission rather than presentation, so they are the only two that fail
     * CLOSED when their closure errors.
     *
     * Everything else here degrades to a benign default — a missing label, an
     * empty option list — and read() is right to hand back the fallback. These
     * two are different: the write path refuses a field whose gate cannot be
     * evaluated, and publishing `false` would have /state draw it editable and
     * then have update() silently drop what the user typed. Reporting the
     * field as locked is the honest half of that pair.
     *
     * A component that was never wired into a Schema is exempt, for the same
     * structural reason FieldPersistence exempts it: every container-backed
     * accessor throws on a bare fixture, and that is not a gate saying no.
     */
    private function gate(object $component, string $method, string $resource, ?string $name, string $property): bool
    {
        if (! method_exists($component, $method)) {
            return false;
        }

        try {
            return (bool) $component->{$method}();
        } catch (Throwable $e) {
            if (! FieldPersistence::isWired($component)) {
                // A bare fixture: every container-backed accessor throws here,
                // so this is never a gate saying no. It still warns when the
                // closure itself was what failed — that is a real diagnostic —
                // and the message check below decides only whether to warn,
                // never the value. Nothing a client sends can reach this
                // branch: on every request path components arrive attached,
                // via Schema::getComponents() or ChildComponents.
                if (! $this->isMissingContainerError($e)) {
                    $this->warnings->add(
                        $resource,
                        $name ?? $component::class,
                        "could not evaluate `{$property}`: " . $e->getMessage(),
                    );
                }

                return false;
            }

            $this->warnings->add(
                $resource,
                $name ?? $component::class,
                "could not evaluate `{$property}`, locking the field: " . $e->getMessage(),
            );

            return true;
        }
    }

    /** @return array<string, mixed> */
    private function node(object $component, string $type, string $resource, string $resourceKey): array
    {
        $name = $this->nameOf($component, $resource);

        $node = [
            'type' => $this->refineType($component, $type, $resource),
            'name' => $name,
            // A Section carries its title as a *heading*, not a label — it uses
            // both HasHeading and HasLabel, and getLabel() stays null. Section
            // is the most common layout component in a real panel, so without
            // this fallback every sectioned mobile form renders unlabelled
            // groups. Fieldset, Tab and Step all take a real $label.
            'label' => $this->read($component, 'getLabel', $resource, $name, 'label')
                ?? $this->read($component, 'getHeading', $resource, $name, 'heading'),
            'helperText' => $this->read($component, 'getHelperText', $resource, $name, 'helperText'),
            'hidden' => $this->gate($component, 'isHidden', $resource, $name, 'hidden'),
            // Two sources, one meaning: "the client cannot put a value here."
            // The gate is the field's own `disabled` state; the second half is
            // the write path's own predicate, because a field Filament will
            // never dehydrate is not editable however it renders — /schema
            // published `dehydrated(false)` as editable, the write answered
            // 201, and the value was dropped. See FieldPersistence for which
            // refusals are publishable and which are only "not filled in yet".
            'disabled' => $this->gate($component, 'isDisabled', $resource, $name, 'disabled')
                || FieldPersistence::neverPersists($component),
            'live' => (bool) $this->read($component, 'isLive', $resource, $name, 'live', false),
            'rules' => $this->rules($component, $resource, $name),
        ];

        // `disabled` says what the panel decided; `writable` says what this
        // package can persist. They are different questions — a panel-disabled
        // field is perfectly persistable, and a file field is not disabled at
        // all — and conflating them is what made the multi-valued relationship
        // flag a lie rather than a limitation. Absent means writable.
        //
        // `file` is checked by type, not by FieldPersistence::neverPersists():
        // an ordinary FileUpload dehydrates and has no relationship, so the
        // component-level predicate has nothing to catch here. Its refusal is
        // Upload being P6 (see config(), which already emits readOnly for the
        // same reason) and RuleExtractor already withholds its rule by this
        // same type check — this mirrors that, rather than teaching
        // FieldPersistence about a Filament type name, which would also flip
        // `disabled` above via the OR it already participates in.
        if ($node['type'] === 'file' || FieldPersistence::neverPersists($component)) {
            $node['writable'] = false;
        }

        $placeholder = $this->read($component, 'getPlaceholder', $resource, $name, 'placeholder');

        if ($placeholder !== null) {
            $node['placeholder'] = $placeholder;
        }

        // Through SafeEvaluator like every other closure: a default reaching
        // for a Livewire request costs this one field's prefill and a
        // warning, never the request. Absent when the component declares
        // none — a null default and no default are different answers to the
        // client.
        $default = $this->read($component, 'getDefaultState', $resource, $name, 'default', null);

        if ($default !== null) {
            $node['default'] = $default;
        }

        $config = $this->config($component, $node['type'], $resource, $name, $resourceKey);

        if ($config !== []) {
            $node['config'] = $config;
        }

        if (in_array($node['type'], ComponentTypeMap::LAYOUT_TYPES, true)) {
            $node['children'] = $this->walk(
                $this->childrenOf($component, $resource),
                $resource,
                $resourceKey,
            );
        }

        return $node;
    }

    /**
     * Descent is ChildComponents' job — see that class for why the obvious
     * accessor is the wrong one. What is left here is the walker's own
     * concern: `Schema::getComponents()` normalizes a bare string into
     * `Text::make()`, but the raw children it falls back to do not, so a legal
     * `->schema(['some text', TextInput::make(...)])` can still hand back a
     * plain string. `ComponentTypeMap::isSkipped()` is typed `object`, so
     * passing one on would be a TypeError that kills the whole walk rather
     * than a warning on one field.
     *
     * @return iterable<object>
     */
    private function childrenOf(object $component, string $resource): iterable
    {
        $kept = [];
        $name = $this->nameOf($component, $resource);

        foreach (ChildComponents::of($component) as $child) {
            if (is_object($child)) {
                $kept[] = $child;

                continue;
            }

            $this->warnings->add(
                $resource,
                $name ?? $component::class,
                'dropped non-component child of type ' . get_debug_type($child),
            );
        }

        return $kept;
    }

    /**
     * Some contract types are a refinement of the class-level mapping: a
     * `select` becomes `multiselect` when multiple, and a `text` becomes
     * `email` or `password` by input type.
     */
    private function refineType(object $component, string $type, string $resource): string
    {
        $name = $this->nameOf($component, $resource);

        if ($type === 'select' && $this->read($component, 'isMultiple', $resource, $name, 'multiple', false)) {
            return 'multiselect';
        }

        if ($type === 'text') {
            if ($this->read($component, 'isEmail', $resource, $name, 'email', false)) {
                return 'email';
            }

            if ($this->read($component, 'isPassword', $resource, $name, 'password', false)) {
                return 'password';
            }

            if ($this->read($component, 'isNumeric', $resource, $name, 'numeric', false)) {
                return 'number';
            }
        }

        if ($type === 'text_entry'
            && $this->read($component, 'isBadge', $resource, $name, 'badge', false)) {
            return 'badge_entry';
        }

        return $type;
    }

    /** @return array<string, mixed> */
    private function config(object $component, string $type, string $resource, ?string $name, string $resourceKey): array
    {
        // Upload is P6. Until then a `file` field always reads as read-only,
        // so a client renders it disabled rather than pretending it can
        // accept an upload the server has nowhere to put.
        if ($type === 'file') {
            return ['readOnly' => true];
        }

        if (in_array($type, ['select', 'multiselect'], true)) {
            // Through read(), like every other closure: a throwing
            // isSearchable() costs this one field's inlining decision, not the
            // whole /schema document.
            //
            // Read BEFORE getOptions(), not after: Filament's own
            // getOptionsFromRelationship() returns null — and so getOptions()
            // an empty array — for a relationship select that is searchable
            // and not preloaded (Select.php:1065), by design, because its
            // options come from the async search endpoint rather than a
            // static list. That withheld-not-empty case is the one this task
            // exists to publish a URL for.
            $searchable = (bool) $this->read($component, 'isSearchable', $resource, $name, 'searchable', false);
            $options = $this->read($component, 'getOptions', $resource, $name, 'options', []);

            if (! is_array($options)) {
                $options = [];
            }

            // `Select::allowHtml()` makes a label a fragment of markup — the
            // idiomatic way to put an icon beside each choice. A browser
            // renders it; a phone's dropdown prints it. Measured on the
            // pilot panel: one 30-option icon picker carried 22.6 KB of inline SVG,
            // 12% of the whole /schema document, and every row read as a wall
            // of `<span class="flex items-center gap-2"><svg …`.
            $html = (bool) $this->read($component, 'isHtmlAllowed', $resource, $name, 'htmlLabels', false);
            $flat = $this->flatOptions($options, $html);
            $cap = (int) config('filament-mobile.options_inline_max', 50);

            // The trigger is knowability, not the panel author's UI
            // preference: `searchable()` alone used to force `optionsUrl`
            // even for a static 3-option select, which pays a network round
            // trip for a list the web panel already has in the DOM. What
            // actually forces a remote lookup is Filament withholding the
            // list (a searchable relationship it will not enumerate, above)
            // or the list having outgrown the wire (the cap — the pilot
            // measured a 55-option list in a DEVELOPMENT database).
            if (($searchable && $options === []) || count($flat) > $cap) {
                return [
                    'optionsUrl' => '/' . trim((string) config('filament-mobile.prefix'), '/')
                        . '/' . $resourceKey . '/options',
                    'searchable' => true,
                ];
            }

            if ($flat === []) {
                return [];
            }

            return ['options' => $flat];
        }

        $config = [];

        // Badge colours (`TextEntry::badge()->colors([...])`) are not emitted
        // in P1: Filament only exposes them through `getColor($state)`,
        // resolved per rendered value against a real record, not as a static
        // map — there is nothing here to read without either a record to
        // resolve against or reaching into the component's internals (which
        // pins this package to a Filament trait's private implementation and
        // is worth avoiding, per review).
        //
        // Icon entries get one exception: a *boolean* icon entry's true/false
        // icon and colour are plain, public, non-state-dependent getters
        // (`getTrueIcon()`, `getFalseIcon()`, `getTrueColor()`,
        // `getFalseColor()`), so those are read directly. A non-boolean icon
        // entry (arbitrary per-state icons/colours) is in the same boat as
        // badge colours and emits no config.
        if ($type === 'boolean_entry' && (bool) $this->read($component, 'isBoolean', $resource, $name, 'boolean', false)) {
            $config['icons'] = [
                'true' => $this->read($component, 'getTrueIcon', $resource, $name, 'trueIcon'),
                'false' => $this->read($component, 'getFalseIcon', $resource, $name, 'falseIcon'),
            ];
            $config['colors'] = [
                'true' => $this->read($component, 'getTrueColor', $resource, $name, 'trueColor'),
                'false' => $this->read($component, 'getFalseColor', $resource, $name, 'falseColor'),
            ];
        }

        return $config;
    }

    /**
     * Filament lets a select group its options — `options(['Egypt' => ['cai'
     * => 'Cairo']])` renders an `<optgroup>`. The contract's option list is
     * flat, so a group contributes its members and its heading is dropped:
     * every value stays selectable, which is what a picker needs.
     * ponytail: no group carried through. Add a `group` key to the contract
     * the day a phone screen actually renders headed sections.
     *
     * @param  array<mixed, mixed>  $options
     * @return list<array{value: mixed, label: string}>
     */
    private function flatOptions(array $options, bool $html = false): array
    {
        $flat = [];

        foreach ($options as $value => $label) {
            if (is_array($label)) {
                $flat = [...$flat, ...$this->flatOptions($label, $html)];

                continue;
            }

            $flat[] = [
                'value' => $value,
                'label' => $html ? $this->plainText((string) $label) : (string) $label,
            ];
        }

        return $flat;
    }

    /**
     * The text inside an `allowHtml()` label. Tags go, entities decode, and
     * the whitespace an SVG's source newlines leave behind is collapsed — a
     * phone renders a string, so what it needs is the string a sighted web
     * user reads, not the markup that draws it.
     */
    private function plainText(string $label): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
    }

    /** @return array<string, mixed> */
    private function rules(object $component, string $resource, ?string $name): array
    {
        $rules = [];

        if ($this->read($component, 'isRequired', $resource, $name, 'required', false)) {
            $rules['required'] = true;
        }

        $max = $this->read($component, 'getMaxLength', $resource, $name, 'max');

        if ($max !== null) {
            $rules['max'] = $max;
        }

        $min = $this->read($component, 'getMinLength', $resource, $name, 'min');

        if ($min !== null) {
            $rules['min'] = $min;
        }

        if ($this->read($component, 'isNumeric', $resource, $name, 'numeric', false)) {
            // The client keys its length-versus-value decision off this, not
            // off the node's `type`: refineType() returns `email` for an
            // ->email()->numeric() field because that is how it renders, and
            // the client would otherwise measure a length where Laravel
            // compares a value — blocking a submission the server accepts.
            $rules['numeric'] = true;
        }

        if ($this->read($component, 'isEmail', $resource, $name, 'email', false)) {
            // The last server rule the wire never carried. RuleExtractor has
            // always emitted it, so an email field 422'd with no published
            // hint at all and the client could not warn before submitting.
            $rules['email'] = true;
        }

        // The panel's own locale, through the same translator the 422 uses, so
        // a hint and the server's eventual answer cannot say different things
        // about the same rule. A client with no published message falls back
        // to its own FilamentStrings.
        $messages = [];

        if ($rules !== []) {
            // getValidationAttribute() is what Filament's own rule closures
            // pass as `:attribute` (CanBeValidated.php) — label-aware
            // ("numeric email" for a field labelled "Numeric Email"), not the
            // raw column name. Reading the column name here would make the
            // published hint and the real 422 describe the same rule with
            // two different nouns. Through read(), like every other closure:
            // a throwing evaluate() costs this field's messages, not the
            // whole document.
            $attribute = $this->read($component, 'getValidationAttribute', $resource, $name, 'validationAttribute', $name ?? '');

            foreach (array_keys($rules) as $rule) {
                // `validation.max` and `validation.min` are not flat strings —
                // Laravel keys them by attribute type (`numeric`, `string`,
                // `array`, `file`), the same way its own validator resolves the
                // 422 message (FormatsMessages::getAttributeType()). Without the
                // suffix, trans() hands back the whole array and every field
                // with a max/min bound publishes an array where the contract
                // promises a string. `max`/`min` here only ever bound a string
                // length or a numeric value, so `numeric` is the one other case
                // to distinguish.
                $key = in_array($rule, ['max', 'min'], true)
                    ? "validation.{$rule}." . (($rules['numeric'] ?? false) ? 'numeric' : 'string')
                    : "validation.{$rule}";

                $messages[$rule] = trans($key, [
                    'attribute' => $attribute,
                    'min' => $rules['min'] ?? '',
                    'max' => $rules['max'] ?? '',
                ]);
            }
        }

        if ($messages !== []) {
            $rules['messages'] = $messages;
        }

        return $rules;
    }

    private function nameOf(object $component, string $resource): ?string
    {
        if (! method_exists($component, 'getName')) {
            return null;
        }

        $name = $this->evaluator->value(
            static fn () => $component->getName(),
            null,
            $resource,
            $component::class,
            'name',
        );

        return is_string($name) ? $name : null;
    }

    private function read(
        object $component,
        string $method,
        string $resource,
        ?string $name,
        string $property,
        mixed $fallback = null,
    ): mixed {
        if (! method_exists($component, $method)) {
            return $fallback;
        }

        return $this->evaluator->value(
            function () use ($component, $method, $fallback) {
                try {
                    return $component->{$method}();
                } catch (Throwable $e) {
                    // A number of accessors (`isDisabled()`, `isLive()`,
                    // `getStatePath()`, `hasInlineLabel()`, ...) fall through
                    // to the parent container when the component's own value
                    // doesn't decide the answer, and that container access
                    // throws on a component never attached to a Schema (a
                    // bare component built outside one, as in a unit test, or
                    // a leaf read before the tree is wired up). That's not a
                    // genuine evaluation failure worth a warning — it just
                    // means nothing overrides the default — so it resolves
                    // to the fallback quietly instead of through
                    // SafeEvaluator's warning path.
                    //
                    // This is identified by the failure itself (an
                    // uninitialized-typed-property Error naming `$container`,
                    // confirmed absent via `hasContainer()`) rather than by
                    // an allowlist of method names, because any accessor —
                    // not just the ones this task calls — can reach the
                    // container the same way. Every other Throwable rethrows
                    // here and is recorded as a warning below, exactly as
                    // before.
                    if (! FieldPersistence::isWired($component) && $this->isMissingContainerError($e)) {
                        return $fallback;
                    }

                    throw $e;
                }
            },
            $fallback,
            $resource,
            $name ?? $component::class,
            $property,
        );
    }

    /**
     * DANGER: this matches exception *message text*, which a client can author
     * — `findOrFail($get('kind'))` embeds the submitted value verbatim in a
     * ModelNotFoundException, and that is how the same pattern became a
     * Critical in FieldPersistence.
     *
     * It is safe here, and only here, because of what both call sites do with
     * it. Each ANDs it behind `! FieldPersistence::isWired()` — a fact about
     * the object that no payload can change — and on request paths components
     * always arrive wired, so the branch is unreachable from the network at
     * all. Inside it, the message decides only whether a warning is recorded;
     * neither `hidden`, `disabled`, nor any published value is derived from it.
     *
     * Both properties are load-bearing. Move this check out from behind
     * `isWired()`, or let it choose a value rather than a log line, and the
     * bug is back — so keep it AND-ed and keep it advisory, or delete it.
     */
    private function isMissingContainerError(Throwable $e): bool
    {
        return $e instanceof Error && str_contains($e->getMessage(), '$container must not be accessed');
    }
}
