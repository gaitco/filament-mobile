<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Error;
use Gait\FilamentMobile\Validation\RuleExtractor;
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
     * `$model` is the resource's model class, consulted only by
     * `isRich()`'s model-declared half (Task 2). Optional, and deliberately
     * NOT stored as constructor state: PanelSchemaBuilder reuses one
     * SchemaWalker across every resource in the panel, each with a different
     * model, so it has to travel through the call the same way `$resource`
     * and `$resourceKey` do. Absent for every bare-component-list unit test,
     * which then gets today's behaviour — no model-declared upgrade, same as
     * before this parameter existed.
     *
     * @param  iterable<object>  $components
     * @return list<array<string, mixed>>
     */
    public function walk(iterable $components, string $resource, ?string $resourceKey = null, ?string $model = null): array
    {
        return $this->walkNodes($components, $resource, $resourceKey ?? $resource, false, $model);
    }

    /**
     * `$insideRepeater` is the one piece of context a node needs from its
     * ancestors: a repeater nested inside another repeater's item template is
     * published `readOnly: true` (see config()), and only the descent knows
     * that it is nested. Threaded rather than published, because it is a fact
     * about where the component sits, not about the component.
     *
     * @param  iterable<object>  $components
     * @return list<array<string, mixed>>
     */
    private function walkNodes(iterable $components, string $resource, string $resourceKey, bool $insideRepeater, ?string $model = null): array
    {
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
                $nodes[] = $this->node($component, $type, $resource, $resourceKey, $insideRepeater, $model);
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
    private function node(object $component, string $type, string $resource, string $resourceKey, bool $insideRepeater = false, ?string $model = null): array
    {
        $name = $this->nameOf($component, $resource);

        $node = [
            'type' => $this->refineType($component, $type, $resource, $model),
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
        // field is perfectly persistable, and a single-file field is not
        // disabled at all — and conflating them is what made the multi-valued
        // relationship flag a lie rather than a limitation. Absent means
        // writable.
        //
        // `file` is checked by type, not by FieldPersistence::neverPersists():
        // an ordinary FileUpload dehydrates and has no relationship, so the
        // component-level predicate has nothing to catch here. Only MULTIPLE
        // stays unwritable — RuleExtractor withholds its rule for the same
        // reason (nowhere to save more than one path per column). A
        // single-file field carries a rule since P6a and is an ordinary
        // writable string column: its stored value is the path the upload
        // endpoint hands back, saved by the unmodified write path. See
        // config(), which publishes the same distinction as readOnly, and
        // isMultipleFile() below — the one read both places share, so the
        // two cannot drift the way they did before this task.
        if ($this->isMultipleFile($component, $node['type'], $resource, $name) || FieldPersistence::neverPersists($component)) {
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
        //
        // `repeater` is withheld entirely, not just read: Repeater::setUp()
        // unconditionally calls defaultItems(1), and its default() override
        // keys every item under a FRESHLY GENERATED random UUID on every
        // single evaluation (Repeater::generateUuid(), verified empirically —
        // two successive /schema calls for the same user produced two
        // different UUIDs for the same field). Publishing that would make
        // `default` — and so the whole document's ETag — different on every
        // request, and the keyed-by-uuid shape isn't the list shape a
        // repeater's value travels as (design spec) regardless.
        if ($node['type'] !== 'repeater') {
            $default = $this->read($component, 'getDefaultState', $resource, $name, 'default', null);

            if ($default !== null) {
                $node['default'] = $default;
            }
        }

        $config = $this->config($component, $node['type'], $resource, $name, $resourceKey, $insideRepeater);

        if ($config !== []) {
            $node['config'] = $config;
        }

        // `repeater` is deliberately NOT in LAYOUT_TYPES (see that constant's
        // docblock): RuleExtractor::childrenOf() and FormDefaults::
        // fromComponents() both recurse LAYOUT_TYPES as pass-through
        // containers, hoisting children to top-level names — exactly wrong
        // for a repeater, whose children are per-item and get `items.*.field`
        // names (Task 2). The walker's own children emission is the one place
        // that needs the item template alongside a layout container's, so it
        // is checked here rather than by widening the shared constant.
        if (in_array($node['type'], ComponentTypeMap::LAYOUT_TYPES, true) || $node['type'] === 'repeater') {
            $node['children'] = $this->walkNodes(
                $this->childrenOf($component, $resource),
                $resource,
                $resourceKey,
                $insideRepeater || $node['type'] === 'repeater',
                $model,
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
     * The one read node() and config() both key their `file` handling off,
     * so "unwritable" and "readOnly" cannot silently disagree the way they
     * did before this task — `node()` had its own independent `=== 'file'`
     * check and `config()` a separate unconditional one. Through read(),
     * like every other closure here: a throwing `isMultiple()` degrades this
     * one field's config rather than the whole document, falling back to
     * `true` — multiple, so published `readOnly: true` and unwritable. The
     * CLOSED answer, deliberately: this is the closure writability keys off,
     * and `false` here offered a control `UploadFieldResolver::resolve()`
     * (whose own try/catch refuses the same throw) would 403 on every
     * upload. `RuleExtractor` withholds the rule on the same throw (its
     * `!== false` check reads the null the same way), so the walker, the
     * extractor and the resolver all give the same closed answer.
     */
    private function isMultipleFile(object $component, string $type, string $resource, ?string $name): bool
    {
        return $type === 'file' && (bool) $this->read($component, 'isMultiple', $resource, $name, 'multiple', true);
    }

    /**
     * `getAcceptedFileTypes()`/`getMaxSize()` for a single-file field,
     * shaped like gate() rather than read(): the caller needs to tell "not
     * configured" (a genuine `null` — publish unrestricted) apart from "the
     * closure threw" (must lock the field, never publish a value at all —
     * see config()'s call site for why read()'s fallback was wrong here).
     *
     * @return array{0: mixed, 1: bool} the value (null if absent or failed), and whether the read failed
     */
    private function readFileConstraint(object $component, string $method, string $resource, ?string $name, string $property): array
    {
        if (! method_exists($component, $method)) {
            return [null, false];
        }

        try {
            return [$component->{$method}(), false];
        } catch (Throwable $e) {
            $this->warnings->add(
                $resource,
                $name ?? $component::class,
                "could not evaluate `{$property}`, locking the field: " . $e->getMessage(),
            );

            return [null, true];
        }
    }

    /**
     * Whether a repeater must be published read-only because its rows are a
     * relationship's.
     *
     * A relationship repeater writes child rows through Filament's own
     * saveRelationships(), which this package's write path never calls — out
     * of scope this slice, so it is refused rather than offered as a control
     * that cannot save.
     *
     * Shaped like readFileConstraint()/gate(), NOT like read(), for the same
     * reason the `file` branch is: read() returns its fallback when the
     * accessor throws, and this gate's fallback (null) is also its "no
     * relationship, publish editable" answer. A throwing getRelationship()
     * therefore failed OPEN — it published rows the write path silently
     * drops. A gate that cannot answer must refuse the field, never admit
     * it. A component with no getRelationship() at all lands in the same
     * refusal: nothing declared these rows writable.
     *
     * The predicate itself lives on FieldPersistence, because RuleExtractor
     * reads the same gate to withhold the field's rules — before that, the
     * node said `readOnly: true` while `WritableNames` said writable. All
     * that is left here is the warning.
     */
    private function refusesRelationship(object $component, string $resource, ?string $name): bool
    {
        $refuses = FieldPersistence::refusesRelationship($component, $error);

        if ($error !== null) {
            $this->warnings->add(
                $resource,
                $name ?? $component::class,
                'could not evaluate `relationship`, locking the field: ' . $error->getMessage(),
            );
        }

        return $refuses;
    }

    /**
     * Some contract types are a refinement of the class-level mapping: a
     * `select` becomes `multiselect` when multiple, and a `text` becomes
     * `email` or `password` by input type.
     */
    private function refineType(object $component, string $type, string $resource, ?string $model = null): string
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

        if ($type === 'text_entry' && $this->isRich($component, $resource, $name, $model)) {
            return 'rich_entry';
        }

        return $type;
    }

    /**
     * The two halves of Filament's own `isProse()` (RichEditor's
     * HasRichContent docs), rewritten because the real one is unusable here.
     *
     * Half 1 — an explicit `->prose()` call — is read exactly like the
     * neighbouring `isBadge` check, through read()'s guarded closure: a
     * throwing gate degrades to `false` (refusal, not an upgrade) and warns,
     * never propagates.
     *
     * Half 2 — `isProse()`'s own second half — calls `getRecord()`, and
     * `MobilePanelController::infolistPaths()` deliberately builds this
     * infolist with no record (see its docblock: passing one would delete
     * BrokenSchemaTest's only coverage of the silent-fallback path). So this
     * half is answered against the model CLASS instead, via
     * `RichContent::attributesFor()` — the one piece of information
     * `infolistPaths()` does have. `$model` is null for the many bare-list
     * unit tests that never call walk() with one, and this half is then
     * simply unreachable, same as before this method existed.
     *
     * Written once, both halves, one place — P6d shipped a defect because
     * the same rule (`$card === null`) was written twice and drifted.
     */
    private function isRich(object $component, string $resource, ?string $name, ?string $model): bool
    {
        if ($this->read($component, 'isProse', $resource, $name, 'prose', false)) {
            return true;
        }

        if ($model === null || $name === null) {
            return false;
        }

        return in_array($name, RichContent::attributesFor($model), true);
    }

    /** @return array<string, mixed> */
    private function config(object $component, string $type, string $resource, ?string $name, string $resourceKey, bool $insideRepeater = false): array
    {
        // Upload is P6a. Multiple stays read-only — nowhere to save more than
        // one path per column this slice, mirroring RuleExtractor's own
        // narrowing. A single-file field genuinely persists (the upload
        // endpoint hands back a path, the ordinary write path saves it like
        // any other string column), so it publishes editable plus the two
        // constraints the server enforces regardless — these are hints for a
        // client to pre-filter and pre-warn with, never the gate.
        if ($type === 'file') {
            if ($this->isMultipleFile($component, $type, $resource, $name)) {
                return ['readOnly' => true];
            }

            // read()'s fallback collapses "never configured" (a legitimate
            // null — publish unrestricted) and "the closure threw" into the
            // same value, and those are not the same field: `avatar` never
            // called acceptedFileTypes()/maxSize() at all, but a THROWING
            // one means UploadController's own constraintsFor() fails
            // closed on every real attempt (empty type allow-list from the
            // same throw). Publishing `readOnly: false` for that field
            // would offer a control the server refuses unconditionally —
            // exactly the "user fills it in, server rejects" failure this
            // package exists to prevent. So this reads through gate()'s
            // catch-and-lock shape instead of read()'s catch-and-degrade
            // one: a gate that cannot answer must refuse the field, never
            // admit it (the same rule `hidden`/`disabled` already apply).
            [$accept, $acceptFailed] = $this->readFileConstraint($component, 'getAcceptedFileTypes', $resource, $name, 'accept');
            [$maxSize, $maxSizeFailed] = $this->readFileConstraint($component, 'getMaxSize', $resource, $name, 'maxSize');

            if ($acceptFailed || $maxSizeFailed) {
                return ['readOnly' => true];
            }

            $config = ['readOnly' => false];

            if ($accept !== null) {
                $config['accept'] = $accept;
            }

            if ($maxSize !== null) {
                $config['maxSize'] = $maxSize;
            }

            return $config;
        }

        if ($type === 'repeater') {
            $config = [
                'addable' => (bool) $this->read($component, 'isAddable', $resource, $name, 'addable', true),
                'deletable' => (bool) $this->read($component, 'isDeletable', $resource, $name, 'deletable', true),
                'minItems' => $this->read($component, 'getMinItems', $resource, $name, 'minItems'),
                'maxItems' => $this->read($component, 'getMaxItems', $resource, $name, 'maxItems'),
                // getItemLabel() takes a $key into an actual item's OWN state
                // (Repeater::getItemLabel() calls getChildSchema($key), which
                // needs a real row) — there is no row yet at schema-generation
                // time, only the template, so this slice always publishes
                // null. See the design spec's wire shape.
                'itemLabel' => null,
                // Published for a host that renders its own repeater; this
                // package's client always treats reordering as false
                // regardless of what is published here (design spec).
                'reorderable' => (bool) $this->read($component, 'isReorderable', $resource, $name, 'reorderable', false),
            ];

            // ALWAYS published, both ways round: the client reads an absent
            // `readOnly` as read-only on purpose (an absent key means a
            // server predating repeater support, which must render inert
            // rather than let a renderer guess it can accept edits), so
            // publishing the key only for the refused case left every
            // ordinary repeater permanently inert on a server that fully
            // supports it. The server's word wins; a client never invents a
            // capability the server did not declare — so the server has to
            // declare it.
            //
            // Three ways to earn it, and the last two are P6c's close-out:
            //
            //  - a RELATIONSHIP repeater: its rows are saved by Filament's
            //    own saveRelationships(), which this write path never calls.
            //  - a NESTED one: two levels of row coordinate is a different
            //    problem, and until now the client rendered a working
            //    Add/Remove whose 422 it had no key to display. Every
            //    document already promised this flag; nothing published it.
            //  - one whose item template holds a child that would not
            //    round-trip. RuleExtractor refuses the same field on the same
            //    predicate (see withheldChild()), so this is the published
            //    half of a refusal the write path already makes — never a
            //    flag on its own.
            $config['readOnly'] = $insideRepeater
                || $this->refusesRelationship($component, $resource, $name)
                || RuleExtractor::withheldChild(ChildComponents::of($component)) !== null;

            return $config;
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
                'label' => $html ? PlainText::of((string) $label) : (string) $label,
            ];
        }

        return $flat;
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
