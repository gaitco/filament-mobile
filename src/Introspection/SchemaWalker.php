<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Error;
use Gait\FilamentMobile\Validation\RuleExtractor;
use stdClass;
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

    /**
     * Sibling names a `confirmed` rule will read, for the walk in progress —
     * `access_token_confirmation` for an `access_token` that declared
     * `->confirmed()`, Laravel's own convention.
     *
     * Per-walk rather than constructor state for the same reason `$model`
     * travels through the call: PanelSchemaBuilder reuses one walker across
     * every resource in the panel, so this is reset at each `walk()` entry
     * and never outlives it.
     *
     * @var list<string>
     */
    private array $confirmationNames = [];

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
        // Materialised before the pre-pass because `$components` may be a
        // generator, and a generator read twice yields nothing the second
        // time — the walk itself would see an empty form.
        $components = is_array($components) ? $components : iterator_to_array($components);

        $this->confirmationNames = $this->confirmationNamesIn($components, $resource);

        return $this->walkNodes($components, $resource, $resourceKey ?? $resource, false, $model);
    }

    /**
     * Every `{field}_confirmation` name some field in this tree will have its
     * `confirmed` rule read against.
     *
     * A pre-pass rather than a per-node question because it is a fact about a
     * field's SIBLING, and a node knows nothing of its siblings. Cheap: one
     * descent, and `declaresConfirmed()` is the silent probe the rules pass
     * already runs on the same components.
     *
     * @param  iterable<mixed>  $components
     * @return list<string>
     */
    private function confirmationNamesIn(iterable $components, string $resource): array
    {
        $names = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            if ($this->declaresConfirmed($component)) {
                $name = $this->nameOf($component, $resource);

                if (is_string($name) && $name !== '') {
                    $names[] = $name . '_confirmation';
                }
            }

            $names = [
                ...$names,
                ...$this->confirmationNamesIn(ChildComponents::of($component), $resource),
            ];
        }

        return $names;
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

        // A `->dehydrated(false)` field that a sibling's `confirmed` rule reads
        // is the one shape this predicate must not lock. Filament's own
        // confirmation idiom never persists the second field — that is the
        // point of it — but the user still has to TYPE it, and publishing it
        // disabled and unwritable made every `->confirmed()` field
        // unsubmittable from the client: the confirmation rendered inert, the
        // payload omitted it, and the server's rule then compared against a
        // key that could never arrive. Verified against the write path, which
        // already handles the sibling correctly once it is sent (matching
        // 200, mismatched 422) precisely because it carries no rule of its
        // own and so never reaches mass assignment.
        $neverPersists = FieldPersistence::neverPersists($component)
            && ! ($name !== null && in_array($name, $this->confirmationNames, true));

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
                || $neverPersists,
            'live' => (bool) $this->read($component, 'isLive', $resource, $name, 'live', false),
            'rules' => $this->rules($component, $resource, $name),
        ];

        // `disabled` says what the panel decided; `writable` says whether the
        // client should SEND this field. Those were the same question until a
        // `confirmed` sibling arrived — a field that must be filled and must
        // never be persisted — so the flag now means "include it in the
        // payload", and the rules array remains the only whitelist deciding
        // what a payload key can actually write. They are still different from
        // `disabled` (a panel-disabled field is perfectly persistable, and a
        // single-file field is not disabled at all), and conflating those is
        // what made the multi-valued relationship flag a lie rather than a
        // limitation. Absent means writable.
        //
        // `file` is checked by type, not by FieldPersistence::neverPersists():
        // an ordinary FileUpload dehydrates and has no relationship, so the
        // component-level predicate has nothing to catch here. Since P12 a
        // MULTIPLE file field is writable too — RuleExtractor admits its
        // rule (a List<String> of stored paths, saved wholesale like a
        // repeater's), so the only file field still unwritable is one whose
        // isMultiple() cannot ANSWER: fileMultiplicity() reads null on that
        // throw, the extractor withholds the rule on the same throw, and
        // UploadFieldResolver refuses the upload through the same
        // WritableNames — all three sites still give the one closed answer.
        // See config(), which publishes the same distinction as readOnly.
        if ($neverPersists
            || ($node['type'] === 'file'
                && $this->fileMultiplicity($component, $node['type'], $resource, $name) === null)) {
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

            // PHP cannot tell an empty MAP from an empty LIST — both are `[]`
            // — so `json_encode` emits a JSON list for either. Every other
            // type on this wire is fine with that ambiguity resolving to a
            // list (an untouched Select/TagsInput/Repeater really does default
            // to a list-shaped nothing), but `keyvalue`'s declared wire shape
            // is `Map<String, String>` in every other case, and a PANEL
            // AUTHOR who never calls ->default() still gets `[]` here because
            // KeyValue::setUp() unconditionally calls `$this->default([])`
            // (measured in vendor). Cast to an empty object so the one case
            // that is genuinely ambiguous resolves to the type the contract
            // promises, not to PHP's tie-break.
            if ($node['type'] === 'keyvalue' && $default === []) {
                $default = new stdClass();
            }

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
     * did before this method existed — `node()` had its own independent
     * `=== 'file'` check and `config()` a separate unconditional one.
     *
     * Three answers, because P12 made multiplicity itself a supported
     * distinction rather than a refusal:
     *
     *  - `false` — a single-file field: ordinary writable string column.
     *  - `true` — a multiple field: writable since P12, a List<String> of
     *    stored paths, published editable with `multiple: true`.
     *  - `null` — not a file component at all, or an isMultiple() gate that
     *    THREW. The throw is the closed answer every site must share:
     *    RuleExtractor withholds the rule on it (its `is_bool` check reads
     *    the null the same way), UploadFieldResolver refuses the upload
     *    through the same WritableNames, and this walker publishes
     *    `writable: false` + `readOnly: true` here. Admitting on a throw
     *    fails OPEN — a control whose every upload 403s, plus a PUT that
     *    could write or clear the column.
     *
     * Through read(), like every other closure here: on a throw the
     * fallback (null) is what distinguishes "cannot answer" from a real
     * bool, and read() still records the warning.
     */
    private function fileMultiplicity(object $component, string $type, string $resource, ?string $name): ?bool
    {
        if ($type !== 'file') {
            return null;
        }

        $multiple = $this->read($component, 'isMultiple', $resource, $name, 'multiple');

        return is_bool($multiple) ? $multiple : null;
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
     * Whether a repeater's relationship gate cannot ANSWER — the one
     * relationship condition that still earns `readOnly` since P9.
     *
     * A resolvable relationship repeater is writable: its rows are child
     * records the write path saves through Filament's own
     * `Repeater::saveToRelationship()`, reached by the controller's relation
     * pass (the P3b machinery a multi-valued relationship select already
     * used). Publishing it read-only — the pre-P9 behaviour — offered a
     * control that could not save; publishing it editable now is what makes
     * the flag honest.
     *
     * What must still fail closed is the gate that throws. Shaped like
     * readFileConstraint()/gate(), NOT like read(), for the same reason the
     * `file` branch is: read() returns its fallback when the accessor throws,
     * and this gate's fallback (null) is also its "no relationship, publish
     * editable" answer. A throwing getRelationship() therefore failed OPEN —
     * it published rows the write path silently drops. A gate that cannot
     * answer must refuse the field, never admit it.
     *
     * The predicate itself lives on FieldPersistence, because RuleExtractor
     * reads the same gate to withhold the field's rules — before that, the
     * node said `readOnly: true` while `WritableNames` said writable. All
     * that is left here is the warning and the error check: a relationship
     * that resolves is not a refusal any more, so the verdict is whether
     * `$error` came back set.
     */
    private function unanswerableRelationshipGate(object $component, string $resource, ?string $name): bool
    {
        FieldPersistence::refusesRelationship($component, $error);

        if ($error !== null) {
            $this->warnings->add(
                $resource,
                $name ?? $component::class,
                'could not evaluate `relationship`, locking the field: ' . $error->getMessage(),
            );

            return true;
        }

        return false;
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
        // Upload is P6a; multiple is P12. A file field genuinely persists
        // (the upload endpoint hands back a path per file, the ordinary
        // write path saves a single path like any other string column, or a
        // List<String> of paths wholesale for a multiple field), so it
        // publishes editable plus the constraints the server enforces
        // regardless — these are hints for a client to pre-filter and
        // pre-warn with, never the gate. `multiple` is ALWAYS present: an
        // absent key means a server predating P12, and a client must never
        // invent a capability the server did not declare.
        if ($type === 'file') {
            $multiple = $this->fileMultiplicity($component, $type, $resource, $name);

            // A multiplicity gate that cannot answer refuses the field —
            // the closed answer RuleExtractor (withholds the rule) and
            // UploadFieldResolver (not in WritableNames → 403) give on the
            // same throw. `multiple: true` is the closed PUBLISHED shape:
            // the field is read-only either way, and a client must never
            // render a stored value of unknown multiplicity as one path.
            if ($multiple === null) {
                return ['readOnly' => true, 'multiple' => true];
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
                return ['readOnly' => true, 'multiple' => $multiple];
            }

            $config = ['readOnly' => false, 'multiple' => $multiple];

            if ($accept !== null) {
                $config['accept'] = $accept;
            }

            if ($maxSize !== null) {
                $config['maxSize'] = $maxSize;
            }

            // Count bounds, present only when the field declared them — a
            // closure that throws degrades that one key through read(), as
            // usual. Single-file fields never carry them: min/max ITEMS are
            // meaningless for one path.
            if ($multiple) {
                $maxFiles = $this->read($component, 'getMaxFiles', $resource, $name, 'maxFiles');

                if (is_int($maxFiles)) {
                    $config['maxFiles'] = $maxFiles;
                }

                $minFiles = $this->read($component, 'getMinFiles', $resource, $name, 'minFiles');

                if (is_int($minFiles)) {
                    $config['minFiles'] = $minFiles;
                }
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
            // Three ways to earn it, and the last is P6c's close-out:
            //
            //  - a NESTED one: two levels of row coordinate is a different
            //    problem, and until now the client rendered a working
            //    Add/Remove whose 422 it had no key to display. Every
            //    document already promised this flag; nothing published it.
            //  - a relationship gate that cannot ANSWER (a throwing
            //    relationship() closure) — fail closed, as everywhere else.
            //    A relationship repeater whose gate resolves is NOT here:
            //    since P9 its rows write through the relation pass, so it is
            //    published editable like any other writable repeater.
            //  - a JSON-column repeater whose item template holds a child
            //    that would not round-trip. RuleExtractor refuses the same
            //    field on the same predicate (see withheldChild()), so this
            //    is the published half of a refusal the write path already
            //    makes — never a flag on its own. The check is skipped for a
            //    relationship repeater: its rows never travel through
            //    `validated()`, so the key-deletion hazard withheldChild()
            //    guards against does not apply to it.
            $config['readOnly'] = $insideRepeater
                || $this->unanswerableRelationshipGate($component, $resource, $name)
                || (! FieldPersistence::savesViaRelationship($component)
                    && RuleExtractor::withheldChild(ChildComponents::of($component)) !== null);

            return $config;
        }

        if ($type === 'tags') {
            // `separator` is published for what it tells the client about the
            // field, never as an instruction to build the delimited form: the
            // wire value is a `List<String>` in every case, and mirroring
            // Filament's own implode into the column happens server-side at
            // write time. Two surfaces, one shape per column.
            //
            // `splitKeys`, `tagPrefix` and `tagSuffix` are deliberately not
            // published — the design spec's stated known weaknesses: a tag
            // commits on submit only, and prefixes/suffixes are presentation
            // this slice does not reproduce. Publishing a key the client
            // ignores is how a contract grows fields nothing honours.
            $separator = $this->read($component, 'getSeparator', $resource, $name, 'separator');
            $suggestions = $this->read($component, 'getSuggestions', $resource, $name, 'suggestions', []);

            return [
                'separator' => is_string($separator) ? $separator : null,
                // `array_values`, and string-only: getSuggestions() evaluates
                // a host closure that may hand back an Arrayable's keyed
                // array, and the contract's list must not arrive as a JSON
                // object. A non-string entry is dropped rather than failing
                // the field — a suggestion is a convenience, never a rule.
                'suggestions' => is_array($suggestions)
                    ? array_values(array_filter($suggestions, 'is_string'))
                    : [],
            ];
        }

        if ($type === 'keyvalue') {
            // The four gates that decide what a user may change — and none
            // of the cosmetics (design spec). `isAddable()`/`isDeletable()`
            // are named after their own setters, but `canEditKeys()`/
            // `canEditValues()` are NOT: the setters are `editableKeys()`/
            // `editableValues()`. Reading the setter name here would return
            // null through read()'s guarded closure, which read() converts
            // to its fallback of `true` — so a locked field would publish as
            // editable, with no error anywhere. Measured in vendor/filament/
            // forms/src/Components/KeyValue.php, not guessed — the same trap
            // P6f's `filament::` prefix and Task 2's
            // `getNestedRecursiveValidationRules()` already sprang once
            // each.
            return [
                'addable' => (bool) $this->read($component, 'isAddable', $resource, $name, 'addable', true),
                'deletable' => (bool) $this->read($component, 'isDeletable', $resource, $name, 'deletable', true),
                'editableKeys' => (bool) $this->read($component, 'canEditKeys', $resource, $name, 'editableKeys', true),
                'editableValues' => (bool) $this->read($component, 'canEditValues', $resource, $name, 'editableValues', true),
                'keyLabel' => $this->read($component, 'getKeyLabel', $resource, $name, 'keyLabel'),
                'valueLabel' => $this->read($component, 'getValueLabel', $resource, $name, 'valueLabel'),
                'keyPlaceholder' => $this->read($component, 'getKeyPlaceholder', $resource, $name, 'keyPlaceholder'),
                'valuePlaceholder' => $this->read($component, 'getValuePlaceholder', $resource, $name, 'valuePlaceholder'),
            ];
        }

        if ($type === 'color') {
            // getFormat() is the ONLY accessor ColorPicker exposes (measured
            // in vendor/filament/forms/src/Components/ColorPicker.php) —
            // 'hex' by default, 'hsl'/'rgb'/'rgba' via the matching helper.
            // Through read(), like every other closure: a throwing format()
            // closure degrades to its own fallback of 'hex' rather than
            // failing the document.
            //
            // The closed-set check runs regardless of whether read() had to
            // fall back, because 'hex' fallback and a host-declared 'sideways'
            // both need the same answer — a client cannot act on a fifth
            // value, and 'hex' is Filament's own default. Task 3's brief
            // states the rule; P7's `direction()` applies the identical one
            // to `filament-panels::layout.direction`.
            $format = $this->read($component, 'getFormat', $resource, $name, 'format', 'hex');

            return [
                'format' => in_array($format, ['hex', 'hsl', 'rgb', 'rgba'], true) ? $format : 'hex',
            ];
        }

        if (in_array($type, ['date', 'datetime', 'time'], true)) {
            // getMinDate()/getMaxDate() return ?string, and hasSeconds()
            // returns bool — all three measured directly on DateTimePicker in
            // vendor/filament/forms/src/Components/DateTimePicker.php, so
            // nothing here needs serialising. `date`/`datetime` never had a
            // branch at all before P8 Task 1.
            //
            // `time` widens this branch rather than copying it (Task 2):
            // TimePicker is five lines in vendor — `extends DateTimePicker`,
            // overriding only `hasDate()` — so all three accessors are
            // inherited unchanged. One reader, three types.
            //
            // The bounds go out exactly as the panel declared them, because
            // getMinDate() is `evaluate($this->minDate)` and nothing more: a
            // TimePicker with `->minDate('09:00')` publishes `"09:00"`, one
            // given a Carbon publishes `"2026-01-01 09:00:00"`. Normalising a
            // bare time into a full datetime would invent a date the panel
            // never chose; the client parses both shapes instead.
            //
            // Every value through read(): a throwing minDate()/maxDate()/
            // seconds() closure degrades that one bound to its fallback
            // (null / false), never the whole document — the same rule every
            // other branch here already follows.
            $config = [
                'minDate' => $this->read($component, 'getMinDate', $resource, $name, 'minDate'),
                'maxDate' => $this->read($component, 'getMaxDate', $resource, $name, 'maxDate'),
                'seconds' => (bool) $this->read($component, 'hasSeconds', $resource, $name, 'seconds', false),
            ];

            // P13: the step grid joins the wire — hoursStep/minutesStep/
            // secondsStep, each published ONLY when its evaluated value beats
            // the vendor default of 1 (measured in vendor: all three are
            // closure-backed ints, `evaluate(...) ?? 1`), so an absent key
            // means 1. `date` nodes carry none: DatePicker inherits the
            // accessors from DateTimePicker and would answer them, but a
            // date has no time grid for a step to act on.
            //
            // Fallback 1 through read(): a throwing step closure degrades
            // that one key to absence, exactly like the bounds above. These
            // keys state what the field was configured with for a host
            // rendering its own picker — the reorderable precedent, not a
            // promise of server-side enforcement (the web panel has none).
            if ($type !== 'date') {
                foreach (['hoursStep' => 'getHoursStep', 'minutesStep' => 'getMinutesStep', 'secondsStep' => 'getSecondsStep'] as $key => $method) {
                    $step = $this->read($component, $method, $resource, $name, $key, 1);

                    if (is_int($step) && $step > 1) {
                        $config[$key] = $step;
                    }
                }
            }

            return $config;
        }

        if (in_array($type, ['select', 'multiselect', 'radio', 'toggle_buttons'], true)) {
            // Through read(), like every other closure: a throwing
            // isSearchable() costs this one field's inlining decision, not the
            // whole /schema document.
            //
            // `radio` widens this branch rather than copying it — Radio uses
            // the very same Concerns\HasOptions trait and getOptions() as
            // Select (measured in vendor) — but it has no isSearchable() at
            // all (no Concerns\CanBeSearchable), so read()'s method_exists
            // guard alone already makes $searchable permanently false for
            // it, with no extra branch needed. `toggle_buttons` (P10) widens
            // it again on the same grounds: ToggleButtons uses that same
            // HasOptions trait and getOptions(), and has no isSearchable()
            // either.
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
            // A radio is never searchable and has no options endpoint to post
            // a query to — the client has no route to resolve `optionsUrl`
            // against — so the inlining cap and the search-endpoint fallback
            // both apply only to select/multiselect. An over-cap radio still
            // inlines every option rather than publish an affordance that
            // cannot work; the panel author's list, however long, is the only
            // list this package can offer on a control with no search box.
            // `toggle_buttons` takes the same ruling (P10): no search
            // affordance, no endpoint, never an `optionsUrl`.
            if (! in_array($type, ['radio', 'toggle_buttons'], true) && (($searchable && $options === []) || count($flat) > $cap)) {
                return [
                    'optionsUrl' => '/' . trim((string) config('filament-mobile.prefix'), '/')
                        . '/' . $resourceKey . '/options',
                    'searchable' => true,
                ];
            }

            // `multiple` is ALWAYS present on a toggle_buttons, a stated gate
            // like a repeater's `readOnly`: the wire value is a scalar
            // (single) or a List (multiple) — exactly the select/multiselect
            // split — and an absent key must never leave the client guessing.
            // Published even when the option list is empty, so it is not
            // collateral of the `$flat === []` fallback below. The boolean()
            // preset needs no special-casing: it publishes options 1/0 and
            // the value travels as declared.
            if ($type === 'toggle_buttons') {
                $config = [
                    'multiple' => (bool) $this->read($component, 'isMultiple', $resource, $name, 'multiple', false),
                ];

                if ($flat !== []) {
                    $config['options'] = $flat;
                }

                return $config;
            }

            if ($flat === []) {
                return [];
            }

            return ['options' => $flat];
        }

        if ($type === 'slider') {
            // P10. `min`/`max` are getMinValue()/getMaxValue() — always
            // present, and the accessors answer the vendor defaults 0/100 on
            // an unconfigured field, which read()'s fallbacks mirror. `step`
            // is published only when getStep() answers an int or float: a
            // string or null step means "any step", and absence of the key is
            // that answer, never an error.
            //
            // `multiple` is always present and is isMultiple(), which vendor
            // computes as `is_array($this->getRawState())` — the raw STATE,
            // not a configured flag (there is no multiple() method). On the
            // empty /schema snapshot no state is seeded at all, so the raw
            // state is null even for a range slider — the array DEFAULT is
            // the one detectable signal there, and is what the fallback read
            // below keys off. A range slider with no array default still
            // publishes `multiple: false` while its server-side rules say
            // `array`: the design spec's stated, accepted weakness — the
            // client renders from the node and a 422 keyed to the field
            // decides anything the hint got wrong.
            $multiple = (bool) $this->read($component, 'isMultiple', $resource, $name, 'multiple', false);

            if (! $multiple) {
                $multiple = is_array($this->read($component, 'getDefaultState', $resource, $name, 'default'));
            }

            $config = [
                'min' => $this->read($component, 'getMinValue', $resource, $name, 'min', 0),
                'max' => $this->read($component, 'getMaxValue', $resource, $name, 'max', 100),
                'multiple' => $multiple,
            ];

            $step = $this->read($component, 'getStep', $resource, $name, 'step');

            if (is_int($step) || is_float($step)) {
                $config['step'] = $step;
            }

            return $config;
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

        if (ComponentTypeMap::for($component) === 'slider') {
            // P10: a slider's bounds are VALUE bounds, which getMaxLength()/
            // getMinLength() above cannot see — Slider has no length
            // constraint, its setUp() force-registers numeric + min:/max:
            // against the value instead (measured in vendor). Published here
            // from the same accessors RuleExtractor::rulesFor() enforces
            // from, so hint and gate cannot drift: the WithPadding variant
            // first (rangePadding folds into the enforced bound — publishing
            // the padding separately would double-count it), the plain one as
            // the fallback for a Filament line that predates it.
            $rules['numeric'] = true;

            $sliderMin = $this->read($component, 'getMinValueWithPadding', $resource, $name, 'min')
                ?? $this->read($component, 'getMinValue', $resource, $name, 'min');

            if (is_int($sliderMin) || is_float($sliderMin)) {
                $rules['min'] = $sliderMin;
            }

            $sliderMax = $this->read($component, 'getMaxValueWithPadding', $resource, $name, 'max')
                ?? $this->read($component, 'getMaxValue', $resource, $name, 'max');

            if (is_int($sliderMax) || is_float($sliderMax)) {
                $rules['max'] = $sliderMax;
            }
        }

        if ($this->read($component, 'isEmail', $resource, $name, 'email', false)) {
            // The last server rule the wire never carried. RuleExtractor has
            // always emitted it, so an email field 422'd with no published
            // hint at all and the client could not warn before submitting.
            $rules['email'] = true;
        }

        if ($this->read($component, 'isUrl', $resource, $name, 'url', false)) {
            $rules['url'] = true;
        }

        $pattern = $this->read($component, 'getRegexPattern', $resource, $name, 'regex');

        if (is_string($pattern) && $pattern !== '') {
            // Undelimited, NOT verbatim — the correction to this rule's first
            // cut, which published the PCRE pattern as Filament stores it and
            // so blocked every submission of a `->regex()` field. Dart's
            // RegExp takes a bare pattern, compiles `/^[a-z]+$/` without
            // complaint, and then matches nothing at all: the leading `/` is
            // a literal the input has to start with, and the `^` behind it
            // asserts a start-of-string that has already been consumed. So
            // the client's own hint refused values the server accepts, and
            // the fail-open path never fired because the pattern is valid.
            //
            // The server side keeps the delimiters — Laravel's `regex:` rule
            // requires them (RuleExtractor) — so this is a wire-shape fix,
            // not a validation change. `contract/panel.json` already carried
            // the undelimited form, which is how the two halves were found
            // to disagree.
            $undelimited = self::undelimitedPattern($pattern);

            if ($undelimited !== null) {
                $rules['regex'] = $undelimited;
            }
        }

        if ($this->declaresConfirmed($component)) {
            $rules['confirmed'] = true;
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

            try {
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

                    $message = trans($key, [
                        'attribute' => $attribute,
                        'min' => $rules['min'] ?? '',
                        'max' => $rules['max'] ?? '',
                    ]);

                    // A key that resolves to a nested group (validation.between)
                    // comes back as an array where the contract promises a
                    // string — skip it rather than publish the wrong shape.
                    if (is_string($message)) {
                        $messages[$rule] = $message;
                    }
                }
            } catch (Throwable $e) {
                // Guarded like PanelSchemaBuilder::direction()'s own __(): a
                // throwing translator costs THIS FIELD'S messages — the client
                // falls back to its own FilamentStrings per rule — never the
                // component, and never the document. This catch is why a bare
                // test-double translator no longer takes down /schema.
                $this->warnings->add(
                    $resource,
                    $name ?? $component::class,
                    'rule message translation threw: ' . $e->getMessage(),
                );
                $messages = [];
            }
        }

        if ($messages !== []) {
            $rules['messages'] = $messages;
        }

        return $rules;
    }

    /**
     * A PCRE pattern with its delimiters stripped, ready for a client whose
     * regex engine takes a bare pattern — or null when this package cannot
     * publish it honestly, which withholds the hint and leaves the server's
     * own `regex:` rule as the only judge.
     *
     * Null on FLAGS, deliberately, rather than stripping them too: Dart has
     * no inline modifiers, so `/foo/i` published as `foo` would be STRICTER
     * on the client than on the server — the same class of bug as the
     * delimiters, only harder to see. Withholding fails open; over-tightening
     * refuses input the panel accepts.
     *
     * Also null on anything that does not parse as delimited at all. PCRE
     * allows any non-alphanumeric, non-backslash, non-whitespace delimiter
     * and pairs the four bracket forms, so `#…#`, `~…~` and `{…}` are as
     * valid as `/…/` and all of them reach here from a real panel.
     */
    private static function undelimitedPattern(string $pattern): ?string
    {
        $open = $pattern[0];

        if (preg_match('/[a-zA-Z0-9\\\\\s]/', $open) === 1) {
            return null;
        }

        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;

        // The LAST occurrence: a same-character delimiter appears escaped
        // inside the pattern (`/a\/b/`), and only the final one closes it.
        $end = strrpos($pattern, $close);

        // `$end === 0` is a one-character string — an opening delimiter with
        // nothing behind it. Anything after the close is a flag.
        if ($end === false || $end === 0 || $end !== strlen($pattern) - 1) {
            return null;
        }

        $inner = substr($pattern, 1, $end - 1);

        return $inner === '' ? null : $inner;
    }

    /**
     * Whether the field declared `->confirmed()` — the only one of the three
     * A6 rules with no accessor of its own. `->confirmed()` registers an
     * ordinary `rule('confirmed', $condition)` (CanBeValidated), so the only
     * honest read is scanning the field's own resolved rule list.
     *
     * Deliberately NOT through read(): a component whose rule list cannot
     * resolve headlessly (a Select whose in-rule needs relationship context,
     * a condition closure needing record state) throws as an ORDINARY event
     * here, and read() would record a warning about a probe, not a defect.
     * The scan fails silently instead — no hint, same degradation as an
     * absent accessor.
     */
    private function declaresConfirmed(object $component): bool
    {
        if (! method_exists($component, 'getValidationRules')) {
            return false;
        }

        try {
            $declared = $component->getValidationRules();
        } catch (Throwable) {
            return false;
        }

        return is_array($declared) && in_array('confirmed', $declared, true);
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
