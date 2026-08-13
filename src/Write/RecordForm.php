<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Write;

use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Validation\RuleExtractor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * The write-path form machinery every record-writing endpoint shares.
 *
 * This class exists because P9 added a SECOND family of write endpoints — the
 * relation row writes (`RelationController::store()/update()/destroy()`) —
 * which must validate and save through the CHILD resource's form exactly the
 * way `MobilePanelController::store()/update()` do through their own. These
 * were private methods on that controller until then; two controllers each
 * keeping a copy is how this package has shipped drift before, so the
 * machinery lives here once and both controllers call it.
 *
 * Everything here is a pure function of its inputs: the resource class, the
 * state, the record (when one exists). Nothing reads the request, the
 * registry, or any controller state, so the two call sites cannot diverge on
 * anything but their inputs.
 */
final class RecordForm
{
    /**
     * The form as it stands for these values — the one place the write path
     * builds a schema, so store() and update() cannot drift apart on which
     * fields exist.
     *
     * The host is seeded with the submitted values, not left empty, because
     * `getComponents()` filters hidden components and a hidden field yields no
     * rule: extracting against an empty form would silently drop the value of
     * every field a `visible(fn (Get $get) => ...)` closure reveals. It also
     * cannot be a null host — `Schema::make()` accepts one, but the first such
     * closure then fatals inside `isHidden()` on `Schema::getLivewire()`, whose
     * return type is not nullable: a 500 on every write to a reactive form.
     *
     * The record is what makes this differ between store() and update(), and
     * it carries both halves: `Schema::record()` *is* `model()`, and the
     * operation is derived from it rather than passed separately, so the two
     * can never disagree about which one this is.
     *
     * ponytail: accepted residual — plain `getComponents()`, not
     * `getComponents(withHidden: true)` the way StateController's read side
     * calls it. A top-level `->hidden()->dehydratedWhenHidden()` field
     * genuinely IS writable (`isDehydrated()` only excludes a field that is
     * hidden and NOT re-dehydrated-when-hidden), but this call drops it before
     * SettledSchema or WritableNames ever sees it, so it silently never
     * writes even though `/state` — which does pass `withHidden: true` —
     * reports it writable. Exotic shape; the honest fix is passing
     * `withHidden: true` on this path too, not a filter over on `/state`.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     * @return list<object>
     */
    public static function components(string $class, array $state, ?Model $record): array
    {
        return $class::form(self::schema(
            $class,
            $state,
            $record === null ? 'create' : 'edit',
            $record,
        ))->getComponents();
    }

    /**
     * The one Schema construction every write endpoint goes through.
     *
     * `Schema::make()` accepts a null host, but that is what makes any
     * `visible(fn (Get $get) => ...)` fatal inside `isHidden()` — see
     * components() above.
     *
     * `->model()` is what Filament's own resource pages set. Without it a
     * `Select::relationship()` resolves no options at all (`getRelationship()`
     * reaches for the schema's model instance and fails on null), so a required
     * foreign key arrives at the phone as an empty picker and every write to
     * that resource 422s on a field the client had no legal value for.
     *
     * `->operation()` is not optional either, and nothing in this package set
     * it until review found the hole. `Schema::getOperation()` falls through to
     * `getLivewire()::class` when unset — here, `HeadlessSchemaHost` — which
     * matches neither `'create'`/`'edit'` nor the `instanceof` branch inside
     * `disabledOn()`/`hiddenOn()`/`visibleOn()`. **Every** operation-scoped
     * gate therefore evaluated false, and `disabledOn('edit')` is *the*
     * idiomatic immutable-after-create gate: slug, sku, email, type.
     *
     * `$record` is passed only where there genuinely is one. It is what makes
     * `disabled(fn (?Model $record) => ...)` answer for the row being edited
     * instead of answering as a create, and a non-nullable `Model $record`
     * hint stop throwing.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     */
    public static function schema(string $class, array $state, string $operation, ?Model $record = null): Schema
    {
        return Schema::make(self::host($state))
            ->model($record ?? $class::getModel())
            ->operation($operation);
    }

    /**
     * The settled schema's rules, narrowed to the names the settle actually
     * allowed — which is the mass-assignment whitelist, so this is the one
     * place every write endpoint decides what may reach the database.
     *
     * The intersection is not belt-and-braces. The returned components are the
     * FINAL pass's, built from state whose non-writable paths were already
     * reset, so that build can report a name the allow-set dropped on an
     * earlier pass: `dehydrated(fn (?string $state) => filled($state))` refuses
     * the submitted `''`, the reset restores the stored value, and the next
     * build then says "writable" — about a value the client never sent. Taking
     * the rules off the build alone lets it through, and the column lands NULL
     * (ConvertEmptyStringsToNull got the `''` first). See
     * SettledSchema::writable().
     *
     * ponytail: the converse is a known, accepted residual — shrink-only can
     * discard a legitimate write behind a 200. Stored `kind='unlock'` opens
     * `gate_note`; the client PUTs `{"kind":"promo","gate_note":"..."}`. Pass 1
     * drops `gate_note` (the submitted `kind` closes it), the reset restores
     * the stored `kind='unlock'`, pass 2 reports it writable again, and
     * shrink-only refuses — 200 OK, typing silently discarded. Reachability is
     * low: `kind` is a Hidden, so a client can never learn it and must invent a
     * contradicting value for a name it was never shown. The upgrade path is a
     * per-field refusal report in the response, which is a contract change, not
     * a fix here.
     *
     * P6c Task 3 finding, fixed here rather than worked around in a test: a
     * plain `array_intersect_key` against `$settled->writable()` was an exact-
     * key match, which held for every rule name before repeaters because
     * `WritableNames::of()` USED to be `array_keys(RuleExtractor::
     * fromComponents(...))` — the same set, by construction. P6c broke that
     * identity on purpose (see the design spec's "two different name
     * spaces"): a repeater's rules also carry per-item paths
     * (`line_items.*.sku`), but `WritableNames` deliberately names only the
     * whole-array `line_items` — `Arr::has()`/`Arr::set()` have no wildcard
     * support, so a starred name may never enter the settle's allow-set.
     * The exact-key intersection this method used to do therefore matched
     * `line_items` but never `line_items.*.sku`, silently dropping every
     * per-item rule from what `$request->validate()` was ever given — a row
     * with an empty required `sku` validated as if the child rule did not
     * exist. `isRuleNameAllowed()` is the fix: an exact match still wins for
     * an ordinary field, and a `name.*.` prefix wins only when `name` ITSELF
     * is writable — so a per-item rule is admitted exactly when its owning
     * repeater is, and a disabled or relationship repeater's per-item rules
     * stay excluded right alongside its own, same as before.
     *
     * @return array<string, mixed>
     */
    public static function rules(SettledSchema $settled): array
    {
        $writable = $settled->writable();

        return array_filter(
            RuleExtractor::fromComponents($settled->components()),
            static fn (string $name): bool => self::isRuleNameAllowed($name, $writable),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * `:attribute` for the rules above, so the 422 names the field the way
     * `/schema`'s published `rules.messages` already does.
     *
     * Both come from the field's `getValidationAttribute()` — Filament's own
     * label-aware attribute, which is what the web panel shows. Without this
     * the validator falls back to the humanised column name and the two
     * describe the same rule with two different nouns. The pilot measured
     * the disagreement on 137 of 187 constrained fields, and on 148 of 187
     * once the panel's locale was Arabic, where the 422 read
     * "title.ar مطلوب" against a published "الاسم (عربي) مطلوب".
     *
     * Filtered by the same predicate as rules(), for the same reason
     * and since the same Task 3 finding: an attribute for a name no rule
     * mentions is inert, but a narrower filter here would make this method
     * and rules() disagree about which fields exist — a repeater's
     * per-item attribute (`line_items.*.sku`) must survive here exactly when
     * its rule does, or its 422 falls back to the humanised path instead of
     * the field's own label.
     *
     * @return array<string, string>
     */
    public static function validationAttributes(SettledSchema $settled): array
    {
        $writable = $settled->writable();

        return array_filter(
            RuleExtractor::attributesFrom($settled->components()),
            static fn (string $name): bool => self::isRuleNameAllowed($name, $writable),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether a RuleExtractor key may reach `$request->validate()`. Three
     * shapes, all keyed off a name that is writable in its own right:
     *
     *  - the exact name — every ordinary field, and a repeater's or a tags
     *    field's own whole-array name;
     *  - `line_items.*.sku` — a repeater's per-item path, which has a child
     *    segment because a repeater has child components;
     *  - `labels.*` — a tags field's per-TAG path, which has NO child
     *    segment because a TagsInput has no children: the per-element rules
     *    are the component's own, through
     *    `HasNestedRecursiveValidationRules`. P7 Task 2 measured this: the
     *    `.*.` prefix alone (the only shape that existed before tags) matched
     *    `line_items.*.sku` and never `labels.*`, so every per-tag rule was
     *    extracted, published, and then dropped here — a 21-character tag
     *    behind `->nestedRecursiveRules(['max:20'])` saved with a 200.
     *
     * See rules()'s docblock for why the exact-match-only predicate
     * that preceded all of this was silently dropping every repeater child
     * rule.
     *
     * @param  list<string>  $writable
     */
    private static function isRuleNameAllowed(string $name, array $writable): bool
    {
        foreach ($writable as $allowed) {
            if ($name === $allowed
                || $name === $allowed . '.*'
                || str_starts_with($name, $allowed . '.*.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one way this package merges anything into a payload: path by path,
     * writing only what the payload does not already answer for.
     *
     * Never array_replace_recursive() and never a spread. PHP has no array
     * merge that is correct for both halves of what arrives here: a spread
     * replaces a whole `caption` map, dropping the locales the payload did not
     * send, and array_replace_recursive() merges LISTS BY INDEX — a default of
     * `['a','b','c']` under a submitted `['c']` stores `['c','b','c']`, a
     * silent corruption of the user's own choice. Trading one for the other is
     * how this bug came back twice.
     *
     * `Arr::has()` is the whole discrimination, and it is per path: the payload
     * answers for `caption.en` but not `caption.ar`, and it answers for
     * `plain_multi` as one indivisible value. An explicitly submitted null is
     * an answer — Arr::has() reads key presence, not truthiness — so a client
     * may still clear a field that has a default.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $paths  flat `path => value`
     * @return array<string, mixed>
     */
    public static function fillMissingPaths(array $payload, array $paths): array
    {
        foreach ($paths as $path => $value) {
            if (Arr::has($payload, $path) || self::collidesWithScalar($payload, $path)) {
                continue;
            }

            data_set($payload, $path, $value);
        }

        return $payload;
    }

    /**
     * Whether an ancestor of this path is already answered by a NON-array
     * value — `caption` submitted as text while a `caption.ar` default waits to
     * be filled in underneath it.
     *
     * `data_set` would replace that text with `['ar' => ...]`, i.e. the default
     * beating the user's own input, which no merge here may ever do. The
     * payload wins: it has answered for the whole attribute, so there is
     * nothing left underneath it to fill.
     *
     * This is the WRITE half of the `title` / `title.ar` collision spec §9
     * records on the serializer, and it is not resolved here: a panel naming
     * both a scalar and its dotted children still gets only one of the two
     * shapes. That is a P3 contract task. This only decides which one loses —
     * the default, never the submission.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function collidesWithScalar(array $payload, string $path): bool
    {
        $segments = explode('.', $path);
        array_pop($segments);
        $prefix = '';

        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            if (Arr::has($payload, $prefix) && ! is_array(Arr::get($payload, $prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record's stored values for the attributes a dotted rule path names,
     * flattened to the same flat `path => value` shape FormDefaults returns.
     *
     * Only those attributes: an undotted path writes its attribute whole, so
     * there is nothing to preserve underneath it, and re-listing every column
     * would put values the form never mentioned into the update array.
     *
     * A list stays a leaf. Descending into one would reintroduce exactly the
     * merge-by-index corruption fillMissingPaths() exists to refuse.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public static function storedPaths(Model $record, array $paths): array
    {
        $stored = [];

        foreach ($paths as $path) {
            if (! str_contains($path, '.')) {
                continue;
            }

            $attribute = explode('.', $path, 2)[0];
            $value = $record->getAttribute($attribute);

            if (is_array($value)) {
                $stored = [...$stored, ...self::leafPaths([$attribute => $value])];
            }
        }

        return $stored;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function leafPaths(array $values, string $prefix = ''): array
    {
        $paths = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = [...$paths, ...self::leafPaths($value, $path)];

                continue;
            }

            $paths[$path] = $value;
        }

        return $paths;
    }

    /**
     * The relation pass: what Filament's own CreateRecord/EditRecord run as
     * `$this->form->model($record)->saveRelationships()` after the attribute
     * save. A `Select::multiple()->relationship()` has no column — this is
     * its only way into the database. Since P9 the same is true of a
     * RELATIONSHIP REPEATER: `Repeater::relationship()` registers its own
     * `saveRelationshipsUsing()` (`Repeater::saveToRelationship()`), and the
     * call below reaches it unchanged — same machinery, not new code.
     *
     * Rebuilt from the SETTLED state rather than reusing the settle's own
     * components, because store()'s settle ran before the record existed and
     * `BelongsToModel::saveRelationships()` refuses without an existing
     * record. The state is the settled one, so every gate the rebuild
     * answers is evaluated against values no crafted payload could have
     * steered — same property the validation pass relies on.
     *
     * The `Arr::has($payload, ...)` guard is the difference between absent
     * and empty, and it is load-bearing: a relation the request never
     * mentioned is not in the settled state (a BelongsToMany is not an
     * attribute, so the trusted floor never carries it), and syncing that
     * absence would CLEAR a pivot the user never touched. Explicit `[]`
     * stays a deliberate clear.
     *
     * The disabled refusal lives in the descent (relationWriteComponents),
     * fail closed — a disabled picker's crafted ids neither attach nor
     * degrade into a clearing sync. A sync that genuinely throws propagates:
     * turning it into a 200 would be the silent data loss this package
     * refuses everywhere else.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $state  the settled state
     * @param  array<string, mixed>  $payload  the raw request payload
     */
    public static function saveRelations(string $class, array $state, Model $record, string $operation, array $payload): void
    {
        $components = $class::form(
            self::schema($class, $state, $operation, $record),
        )->getComponents();

        foreach (RuleExtractor::relationWriteComponents($components) as $name => $component) {
            if (! Arr::has($payload, $name)) {
                continue;
            }

            // A present NULL is not a deliberate clear; explicit `[]` is.
            // Without this, `{"tag_rows": null}` destroyed every child row of
            // a relationship repeater behind a 200 — the shape a client sends
            // for a writable field it holds no value for, which is exactly
            // what a relationship repeater was before its rows were published
            // (see MobilePanelController::repeaterRelationRows()). Publishing
            // the rows stops this client from sending that null; refusing to
            // read a null as "delete everything" is the half that also holds
            // for a client this package did not write. Absence and null now
            // degrade the same way, and only `[]` clears.
            if (Arr::get($payload, $name) === null) {
                continue;
            }

            $component->saveRelationships();
        }
    }

    /** @param  array<string, mixed>  $state */
    private static function host(array $state): HeadlessSchemaHost
    {
        $host = new HeadlessSchemaHost();
        $host->setMobileState($state);

        return $host;
    }
}
