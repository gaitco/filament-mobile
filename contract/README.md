# Contract fixtures

Golden JSON for the `filament_mobile` panel schema, contract version 1.

Both sides of the project test against these files:

- **Dart** (`dart/filament_mobile/test/contract_test.dart`) parses them and
  asserts the resulting object graph.
- **Laravel** (`laravel/filament-mobile/tests/Feature/ContractSnapshotTest.php`)
  generates `laravel-panel.json` from its fixture resources and asserts the
  committed copy still matches. Regenerate with
  `UPDATE_SNAPSHOTS=1 vendor/bin/pest tests/Feature/ContractSnapshotTest.php`.

`panel.json` and `laravel-panel.json` are both golden and answer different
questions. `panel.json` is hand-written and covers *every* v1 shape, including
ones no fixture resource happens to use — it is what the Dart parser is tested
against, and it is never regenerated. `laravel-panel.json` is what the Laravel
side *actually emits*, so a silent change in Filament's introspection surface
shows up as a diff there.

## The panel's `locale` and `direction`

`panel` carries two more keys, added in P6f:

```jsonc
"panel": {
  "id": "mobile",
  "title": "Laravel",
  "locale": "en",
  "direction": "ltr"
}
```

`direction` is a **closed set**: exactly `"ltr"` or `"rtl"`, never anything
else — see `laravel/filament-mobile/README.md`'s Locale and direction
section for where it comes from and the namespace it must be read through.
`locale` is `app()->getLocale()` at request time.

**`panel.json` does not carry either key, deliberately** — it predates
P6f and was never regenerated, so it doubles as the fixture for the case
that matters most to a client: a server that has not shipped this feature
yet. Both sides read an absent `direction` as `ltr`, the same safe fallback
the server itself normalises an unrecognised value to; a client should
never treat the key's absence as an error. `laravel-panel.json` is
regenerated from a real request and does carry both keys.

## `panel.locales` and the node-level `translatable` key

P17 adds one more `panel` key and one more form-node key, both additive:

```jsonc
"panel": {
  "id": "mobile",
  "locale": "en",
  "direction": "ltr",
  "locales": ["ar", "en"]
}
```

`panel.locales` is a **flat `list<string>`, in the order the panel
declared them** — either the official
`filament/spatie-laravel-translatable-plugin`'s own locales when that
plugin is registered, or `config('filament-mobile.locales')` for a panel
built on this package's own manual dotted-field convention
(`caption.ar`/`caption.en`) instead. **Absent — never `[]` — when neither
source answers.** A client uses this array for chip ORDERING only; the
chips a form actually renders come from the fields themselves, per the
node-level key below, not from this list. Never derive a locale from a
dotted field name alone: a `category.name` `BelongsTo` path is dotted too,
and this key is the only evidence a field is genuinely per-locale.

A translatable form node gains one more key, only when it is true:

```jsonc
{ "type": "text", "name": "caption.ar", "translatable": true }
```

`translatable` is published **only when `true`** — the same
only-when-present shape as `writable` and `placeholder` — on a leaf whose
`name` is **dotted** and whose head segment (everything before the first
dot) is one of the underlying model's real translatable attributes. It is
never `false`, never on an undotted field (see `DoctorCommand`'s
Translatable diagnostic for that shape — the official plugin's own
one-field-per-locale convention, which mobile edits at whatever locale the
request resolves to, with no switcher of its own), and never on a dotted
field that merely looks like one (a `caption.ar`/`caption.en` pair on a
model with only an `'array'` cast and no real `HasTranslations` trait
stays unannotated — see `record-payload.json`'s sibling reasoning for why a
fixture with no real feature-backing keeps a golden byte-identical). A
client derives `{attribute, locale}` by splitting `name` at its **last**
dot — the server publishes no second copy of a fact the name already
carries — and groups sibling `translatable` leaves that share the same
head into one chip-switched field instead of rendering N stacked
"Ar"/"En"-labelled fields.

**`panel.json` and `laravel-panel.json` carry neither key, deliberately**
— the same "server predating this feature" fixture role
`panel.locale`/`direction` already established above: no fixture behind
either golden has a real `HasTranslations` model, a registered
translatable plugin, or a `filament-mobile.locales` config value, so both
keys staying absent from both goldens is a structural guarantee, not a
maintenance step someone can forget.

## Reordering (`resource.reorder`)

P18 adds one more resource-level key, present only on a resource that opted
in on the web panel's own table:

```jsonc
{
  "key": "slides",
  ...
  "sorts": [],
  "reorder": { "column": "position", "direction": "asc" }
}
```

`reorder` mirrors exactly three things the web panel's `table()` already
declares — `->reorderable('column', condition: bool|Closure, direction:
'asc'|'desc')` and `->authorizeReorder(fn (...) => bool)` — never a second
permission model or a mobile-only reorder toggle. The key is present **if
and only if**:

- the table declares a reorder column (`->reorderable('column')`), **and**
- that column is **not dotted**. A dotted column (`'pivot.position'`) is
  Filament's *other* reorder branch — a `BelongsToMany` pivot reorder — which
  is a P18 non-goal, so a pivot-reordered resource reads exactly like one
  with no reorder column at all, and **is never offered on mobile**, **and**
- `->reorderable()`'s own `condition` (default: `true`) evaluates `true`,
  **and**
- `authorizeReorder()` (default: always authorized, same as Filament's own
  default) evaluates `true` for the requesting user.

**Absent — never `null`, never `{ "enabled": false }` — whenever any of
those three is not true.** This is the same fail-closed shape `group` and
the navigation `badge` already use above: an old client that has never
heard of `reorder` ignores the unknown key exactly as it ignores any other,
so absence degrades safely, while a published-but-disabled key would need
every client, forever, to remember to check it. A client sees the key at
all only for a resource it may drag-reorder for *this* user; it never has
to ask permission a second time before issuing the write the list-drag
endpoint exposes (P18 Tasks 3–4 define that flag and endpoint).

`laravel-panel.json` carries one reorderable fixture resource so this key's
shape is exercised end to end, not merely asserted in isolation; `panel.json`
predates P18 and — the same "server before this feature shipped" role every
other additive key's golden plays above — carries no `reorder` key at all.

### The list endpoint's `?reorder=1` flag

A resource whose schema carries `reorder` (above) also serves its full,
reorder-ordered list off the SAME endpoint the normal paginated list uses —
`GET {resource}?reorder=1`, exactly the string `'1'`; any other value
(`0`, `yes`, absent) is the ordinary paginated list, unchanged.

`?reorder=1` mirrors Filament's own reorder-mode table query, not a new
policy:

- **All records, unpaginated**, ordered by the declared reorder column and
  direction — never `per_page`-sliced. `meta` still carries all four of the
  normal list's keys, synthesized rather than read off a paginator:
  `{current_page: 1, last_page: 1, per_page: <count>, total: <count>}` —
  `<count>` is the full result count, so a client that does not branch on
  `reorder` still renders a coherent single page.
- **`sort`/`direction` are ignored, silently — never a 422**, even for an
  unknown `sort` key. Filament discards the table's sort column outright the
  moment it is reordering; this endpoint does the same by never evaluating
  `sort`/`direction` at all in this mode.
- **`search` is still applied.** Filament keeps its search filter active
  while reordering — reorder mode narrows the same way the normal list does,
  it just does not paginate or honour `sort`.

**`422 "Resource [{key}] is not reorderable."`** whenever the resource's
`reorder` schema key would be absent for this request — no declared reorder
column (or a dotted pivot column, P18's non-goal), `->reorderable()`'s own
`condition` evaluates `false`, or `authorizeReorder()` evaluates `false` for
the requesting user. A client should only ever send
`?reorder=1` for a resource whose `/schema` response carried `reorder` for
this session; sending it for one that didn't is the same misuse
`ListQuery`'s unknown-sort-key abort already answers with a 422 elsewhere on
this endpoint.

### The write endpoint — `POST {resource}/reorder`

The drag itself. Body:

```jsonc
{ "order": ["<routeKey>", "<routeKey>", "…"] }
```

`order` names every dragged row's **route key** (the value the client already
holds — the same one `data.id` publishes; the serializer publishes `data.id`
through the model's own `getRouteKeyName()`, so `data.id` IS the route key,
not merely equal to it by coincidence), in the row's NEW top-to-bottom
position, first entry first. The server mirrors Filament's own `reorderTable()`
(`Filament\Tables\Concerns\CanReorderRecords::reorderTable()`) for the
non-pivot branch: `callBeforeReordering($order)`, one `UPDATE … CASE …`
inside a single database transaction (`whereIn($keyName,
$order)->update([$column => <case expression>])`), then
`callAfterReordering($order)` — run *outside* that transaction, exactly where
Filament itself calls it, so a hook that throws there never rolls back a
write that has already committed. A `direction: 'desc'` resource (declared on
the `reorder` schema key, above) reverses the CASE assignment the same way
Filament's own builder does: the first posted key gets the HIGHEST position,
not the lowest.

Exactly one `UPDATE` runs on the happy path — no per-row writes, no N+1.
That `UPDATE` runs through Eloquent's `Builder::update()`, which stamps
`updated_at` on every touched row the same as Filament's own web reorder
does — not a side effect this package adds.

**`200 {"message": "Reordered."}`** on success. This is a plain,
package-owned string, not a mirror of Filament's own reorder notification
text: that text fires through a Livewire `Notification`, which this package
does not host headlessly.

Rejection table:

| Condition | Status | Body |
|---|---|---|
| Resource key names nothing this panel serves | 404 | `"No mobile resource [{key}]."` |
| Resource exists but declares no reorder column at all (or a dotted pivot column) | 404 | **The same message as the row above**, byte-for-byte — a resource that merely isn't reorderable must be indistinguishable from one that doesn't exist, or a caller could probe resource keys by watching this 404 turn into something else |
| A reorder column IS declared, but `->reorderable()`'s own `condition` evaluates `false`, or `authorizeReorder()` evaluates `false`, for the requesting user | 403 | — Both fold into Filament's own single `isReorderable()` gate (`filled(column) && evaluate($condition) && isReorderAuthorized()`), which this package reads through its public surface only, so a disabled `condition` and a denying `authorizeReorder()` are indistinguishable from each other — same as they are to Filament's own web panel — never confused with the 404 above, since the column itself IS declared |
| `order` missing, or not a JSON array | 422 | — |
| `order` is `[]` | 422 | — |
| `order` contains anything other than an int or string (bools included — a bool would otherwise survive `whereIn()` as `0`/`1` and silently match a real row) | 422 | — |
| `order` contains a duplicate route key | 422 | — |
| `order` names a route key outside the resource's own query — soft-deleted, scoped away by a global scope or tenancy, or never existed | 422 | `"Unknown record in order."` — and **nothing is written**: this check runs before the transaction opens |
| The `UPDATE` itself fails (e.g. a misconfigured reorder column) | 500 | Laravel's own error response; the transaction rolls back, so every row's position is exactly what it was before the request |

Every check above runs **before** the transaction opens, membership included
— the only query capable of writing anything is the single `UPDATE`, and it
either fully commits or (the 500 row) fully rolls back. There is no partial
reorder.

If the model's route key name differs from its primary key name, the server
resolves every submitted route key to its primary key first — through the
resource's own base query, which is also what performs the membership check
above — and runs the CASE update on primary keys, the same way Filament's own
Livewire table does (its sortable list is keyed by model key, not by a
custom route key).

## Reading `hidden` and `disabled` as a client

`hidden` does not mean the same thing on the two endpoints, and only one of
them is worth trusting:

- **`/schema`'s `hidden` is a first-paint hint only.** It is a snapshot of an
  *empty* form — no record, no typed values — so every
  `visible(fn (Get $get) => ...)` field answers as if nothing had been filled
  in. Use it to avoid drawing an obviously conditional field on first paint;
  never treat it as truth, and never use it to decide whether to send a value.
- **`/state`'s `hidden` is authoritative.** It is re-evaluated against the
  record and the values the phone has typed, which is the whole reason that
  endpoint exists. Re-fetch it when a `live` field changes and render from
  what comes back.

`disabled` is the same snapshot on `/schema` and the same authority on
`/state`, with one guarantee added: a field the server would *never* persist —
`dehydrated(false)`, or a dehydration gate that errors — is published
`disabled: true` on both. Filament's own web panel drops such a value on save,
so an editable-looking control there would collect input that the write
endpoint silently discards behind a `201`. A field that is merely unfilled
(`dehydrated(fn ($state) => filled($state))`, the usual password idiom) stays
`disabled: false` — it is writable, just not yet written.

A change that breaks either side fails both. Edit these files only together
with the spec at `docs/superpowers/specs/2026-08-02-filament-mobile-design.md`.

## The `actions` array on a record

`GET /{resource}/{record}` carries an `actions` array beside `permissions`,
one node per action the record's own `table()` opted into the mobile API
that THIS record specifically may run right now:

```jsonc
"actions": [
  {
    "name": "approve",
    "label": "Approve",
    "color": "success",
    "icon": "heroicon-o-check",
    "confirmation": {
      "heading": "Approve this?",
      "description": null,
      "submit": "Approve",
      "cancel": "Cancel"
    }
  }
]
```

Two rules, and both are load-bearing:

- **Always present, `[]` when the resource opted no actions in.** A client
  never has to distinguish "the key is missing" from "there is nothing to
  run" — there is only one shape.
- **Absence means unavailable, never disabled.** An action hidden or
  unauthorized for this specific record is not in the array. There is no
  `enabled: false` to render greyed out — the same rule `permissions`
  already follows on this endpoint, and for the same reason: a control the
  server would refuse must not be drawn at all.

`confirmation` is `null` when the action needs no confirmation. When it is
non-null, `submit`/`cancel` being **empty strings** means "the client
substitutes its own button text" — the server's fail-closed answer for a
confirmation whose own copy closure threw. It never means "skip the
prompt"; a non-null `confirmation` is itself the instruction to ask.

`contract/laravel-panel.json` is a `/schema` snapshot, and `/schema` never
carries `actions` — they are a record-scoped fact, evaluated per record on
`GET /{resource}/{record}`, not a resource-scoped one. Neither `panel.json`
nor `laravel-panel.json` carries this shape; a golden fixture for it would
live against the record endpoint, not the schema one, if one is ever added.

**The schema-level `actions` key in the design spec (§5.5) was never built,
and its client types are gone as of 0.3.0.** The P0 spec sketched `actions`
as a resource-level array with `row`/`bulk`/`header` scope and inline modal
forms. Laravel never emitted that key on `/schema`, nothing rendered it, and
the Dart types that parsed it (`ResourceAction`, `ActionScope`,
`ActionConfirmation`) were deleted rather than left as a second, dead way to
say "action" — they had already cost a naming collision. P3c's design is the
only one: record actions travel on the record payload, because an action's
visibility is a fact about one record, not a static fact about a resource.
See `docs/superpowers/specs/2026-08-06-p3c-button-actions-design.md`.

## The `/dashboard` endpoint and `dashboard.json`

`dashboard.json` is a golden fixture, but not the same kind as `panel.json`
and `laravel-panel.json`: it is generated **through the real endpoint**
(`DashboardSnapshotTest` in the Laravel repo), not hand-written, because a
dashboard widget's shape is inseparable from Filament's own `Stat`/`Chart`
classes — there is no useful "every v1 shape" fixture to hand-write
separately from what a real widget actually emits. Regenerate it the same
way `laravel-panel.json` is regenerated. Both sides test against the one
file: Dart's `dashboard_data_test.dart` parses it, Laravel's
`DashboardSnapshotTest` asserts the committed copy still matches.

`GET /api/mobile-panel/dashboard` returns `{"widgets": [...], "direction":
"ltr"}` — `direction` added in P6f, the same closed `ltr`/`rtl` answer
`/schema`'s `panel.direction` publishes, from the one shared method
(`PanelSchemaBuilder::direction()`) both endpoints call. `/dashboard` needs
its own copy of the key because it carries no `panel` block for a client to
read a direction off of. Each entry a `"stats"` or `"chart"` node — see
`laravel/filament-mobile/README.md`'s Dashboard section for the full shape
and the gate/degradation rules. Two rules a client must honour, both
load-bearing:

- **`value` is a string — or null when the panel's value has no renderable
  string form — never re-formatted.** `Stat::getValue()` is
  `mixed` on the Laravel side — an int, a float, `Number::abbreviate()`
  output, an already-formatted money string — and the server stringifies it
  exactly once because only the panel knows its own formatting intent. A
  client that parses `"1,340"` back into a number and re-renders it risks
  producing `1340` where the panel meant `1.3k`, or dropping a currency
  symbol the panel deliberately included. Display the string as sent.
- **`datasets` is normalised, not Chart.js passthrough.** Filament's
  `ChartWidget::getData()` returns a Chart.js structure whose shape varies
  by chart type and carries styling keys a phone has no use for. Only
  `label` and a numeric `data` list survive; a dataset without numeric
  `data` was dropped server-side, with a warning, rather than shipped as
  "whatever Chart.js happened to return". A client should not expect
  `datasets` to carry colours, point styles, or any other Chart.js-specific
  key — those never travel.

## The file upload field

A `FileUpload`/`SpatieMediaLibraryFileUpload` node — single or
`->multiple()` — publishes `config.readOnly: false` plus hints for a client
to pre-filter and pre-warn with, all read straight off the component:

```jsonc
{
  "type": "file",
  "name": "attachments",
  "config": {
    "readOnly": false,
    "multiple": true,
    "accept": ["image/png", "image/jpeg"],
    "maxSize": 5120,
    "maxFiles": 5,
    "minFiles": 1
  }
}
```

- **`multiple`** — **always present on every `file` node on a current
  server**, `false` for a single-file field: a stated gate, never inferred.
  A client reads an *absent* `multiple` as `false`, because absence means a
  server predating multi-file support, and a client must never invent a
  capability the server did not declare.
- **`accept`** — the field's `acceptedFileTypes()`, or absent when the field
  never called it (unrestricted). Per file, multiple or not.
- **`maxSize`** — kilobytes, Filament's own unit for `maxSize()`; absent
  when the field never set one. Per file, multiple or not.
- **`maxFiles` / `minFiles`** — present only when a `->multiple()` field
  declared them. These two are not merely hints: the write path carries
  them as real validation rules on the array — `max`/`min` with **count
  semantics**, because Laravel's `min`/`max` on an array count its elements
  — plus a per-element `string` under `attachments.*`, so an over-count
  submission is a `422` on the field and a crafted non-string element a
  `422` keyed `attachments.0`. The count bound is the server's rule, not
  the client's.

The other hints are hints only — see `laravel/filament-mobile/README.md`'s
Upload section for how the upload endpoint re-derives and enforces
`accept`/`maxSize` server-side, per file, regardless of what a client
sends.

A field whose `acceptedFileTypes()`/`maxSize()` closure throws publishes
`config.readOnly: true` with no `accept`/`maxSize` — the same shape a
disabled field already uses, multiple or not. For the throwing case this is
deliberate, not a missed config: the server would refuse every upload
attempt against that field regardless of what was sent, so publishing an
editable control would be offering a capability that cannot work. The
multiplicity gate fails the same closed way: a field whose `isMultiple()`
closure throws publishes `readOnly: true` with `multiple: true` and no
hints, its write rule is withheld, and the upload endpoint refuses it — all
three sites agree on the same closed answer.

**The field's value on the wire is stored paths, never bytes.** Uploading
happens on a separate endpoint (`POST /{resource}/upload`) before the form
is ever submitted; the value this node carries — on `/state`, on a record,
in a create/update payload — is the path (single) or list of paths
(multiple) that endpoint returned, saved and read back exactly like any
other column. For a `multiple: true` field the value is **always a
`List<String>`**, in every payload direction; sending a scalar string for a
multiple field (or a list for a single one) is a `422`, not a coercion.
Removal is wholesale-replacement, the relationship-repeater model: a
submitted list is the whole new set, a submitted empty list clears the
column (unless a `minFiles`/`required` forbids it), and a field the
submission never mentions is untouched. A client never inlines file bytes
into a schema payload, a state payload, or a write body.

**Multiplicity does not change the upload endpoint — one file per
request.** A multi-file field is served by N calls: each pick uploads
through the same endpoint and the client appends the returned path to the
field's list. Per-file enforcement (`accept`, `maxSize`) applies to every
call exactly as it does for a single-file field.

## The radio field

A `Radio::make('plan')->options([...])` publishes a `radio` node, using the
**same option shape `select` already does**:

```jsonc
{
  "type": "radio",
  "name": "plan",
  "label": "Plan",
  "config": { "options": [
    { "value": "monthly", "label": "Monthly" },
    { "value": "yearly", "label": "Yearly" }
  ] }
}
```

`Radio` shares `Select`'s `Concerns\HasOptions`, so its `options` are read and
flattened by the same code — a client that already renders a `select` node's
`config.options` needs nothing new to render a `radio`'s.

**One difference from `select`/`multiselect`: `config.optionsUrl` never
appears on a `radio` node, however many options it has.** The other two types
fall back to an async search endpoint once their option count passes
`options_inline_max`; a radio has no search affordance and nothing to post a
query to, so that fallback would publish a capability no client could use. An
over-cap radio inlines its full option list instead — a client rendering this
type should never expect `optionsUrl` and must be able to render an arbitrarily
long inline list.

**Not on the wire, deliberately:** `Radio::isInline()`. Options always stack
one per row; that is the right treatment on a phone regardless of what the
panel configured.

## The toggle_buttons field

A `ToggleButtons::make('status')->options([...])` publishes a
`toggle_buttons` node, carrying the **same flattened option shape**
`select`/`radio` already publish — read through the same walker branch,
widened like `radio` was, not copied:

```jsonc
{ "type": "toggle_buttons", "name": "status", "label": "Status",
  "rules": { "required": true },
  "config": {
    "multiple": false,
    "options": [ { "value": "draft", "label": "Draft" } ]
  } }
```

- **`config.multiple` is always present**, a stated gate like a repeater's
  `readOnly` — never inferred from absence. The value is a scalar when it is
  `false` and a `List` when `true`: exactly the `select`/`multiselect` split,
  through the ordinary `default`/`/state`/write paths.
- **`config.optionsUrl` never appears on a `toggle_buttons` node**, however
  many options it has — the radio ruling, for the radio's reason: the control
  has no search affordance and nothing to post a query to. An over-cap field
  inlines its full option list, and a client rendering this type must render
  an arbitrarily long inline list.
- **The `boolean()` preset needs no client special-casing.** It is an
  options/colors/icons preset over `1`/`0`; it publishes options `1`/`0` and
  the value travels as declared.

A value that is not one of the published options is the server's to refuse —
Filament builds an `in:` rule from the enabled option keys, the same
enforcement a select already relies on. The client renders the options and
pre-empts nothing.

**Not on the wire, deliberately:** per-option colors, icons, tooltips,
per-option disabled state, and `inline`/`grouped`/`hiddenButtonLabels`. All
are presentation; a disabled option is enforced server-side by that same
`in:` rule.

## The slider field

A `Slider::make('rating')->range(0, 10)->step(1)` publishes a `slider` node:

```jsonc
{ "type": "slider", "name": "rating", "label": "Rating",
  "rules": { "required": true, "numeric": true, "min": 0, "max": 10 },
  "config": { "min": 0, "max": 10, "step": 1, "multiple": false } }
```

- **`config.min` / `config.max` are always present** — the accessors answer
  their own defaults (0/100) for an unconfigured field, so an absent key
  reads as those defaults, never as an error.
- **`config.step` is present only when the declared step is a number.**
  Filament allows a string step; one publishes nothing, and absence means
  "any step", never an error.
- **`config.multiple` is always present — and on `/schema` it is a snapshot,
  not a promise.** Filament decides range mode from the *state being an
  array* (`isMultiple()` is `is_array($this->getRawState())`; there is no
  `multiple()` method), and `/schema` walks a deliberately unseeded form, so
  the walker falls back to `is_array(getDefaultState())`: on `/schema` only a
  range slider declared with an array `->default([20, 40])` publishes
  `multiple: true`. `/state` re-answers from real state. **A range slider
  with no array default therefore publishes `multiple: false` on `/schema`
  while its rules still say `array`** — a known, documented weakness. The
  client rule is the usual one: render from the node, but never let a client
  hint block a submission the server decides on; a `422` keyed to the field
  lands on the field as usual.
- **The bounds in `rules` are re-derived, not copied.** `Slider::setUp()`
  force-registers `numeric`/`min:`/`max:` — and `integer` or
  `multiple_of:{step}` when the step is set — behind rule closures keyed off
  raw state, which the ordinary accessor reads cannot see. The server
  re-derives them from `getMinValue()`/`getMaxValue()`/`getStep()`, the same
  accessors `config` is read from, so hint and gate cannot drift.
  `rangePadding` is folded into the enforced bound via the
  `getMinValueWithPadding()`/`getMaxValueWithPadding()` variants; publishing
  the padding separately would double-count it. A range slider's per-element
  rules (`numeric`/`min:`/`max:` per element) ride the existing `name.*`
  nested-recursive machinery, and its container rule is `array`/`list`.
- **The value is a number, or a two-element `List` in range mode** — through
  the ordinary `default`/`/state`/write paths.

**Not on the wire, deliberately:** pips (mode/density/values/formatter/
filter/stepped), tooltips, behavior, fillTrack, vertical, rtl,
nonLinearPoints, minDifference/maxDifference, rangePadding (folded into the
enforced bounds, above), decimalPlaces.

## The Placeholder field

A `Placeholder::make('note')->content(...)` in a form publishes as the
existing **`text_entry`** type — no new type at all. The component extends
`Infolists\Components\TextEntry` (it is a deprecated alias), carries no
writable state, and no rule is admitted for an entry-typed node, so it is
read-only with zero new machinery on either side. In a form it **renders
nothing**: an entry type in a form is out of that registry's scope, and the
client draws a `SizedBox.shrink()`. A crafted payload that submits a value
for it gets a **`201`, not a `500`** — the name is never admitted to the
write, so there is no column for a bad write to reach. That refusal shape is
pinned by test.

`ViewField` is the deliberate counterexample: it stays **unmapped by
design** — an arbitrary Blade view has no data contract to read — and keeps
the existing drop-with-warning treatment (`doctor` names it, its rule is
withheld so its state is discarded on write). See
`laravel/filament-mobile/README.md`'s Supported form inputs.

## The date, datetime and time fields

`DatePicker`, `DateTimePicker` and `TimePicker` publish `date`, `datetime` and
`time` nodes. All three carry the same config, because `TimePicker` is
`DateTimePicker` with `hasDate()` overridden to `false` and inherits every
accessor:

```jsonc
{ "type": "datetime", "name": "published_at",
  "config": { "minDate": "2026-01-01", "maxDate": "2026-12-31", "seconds": true } }

{ "type": "time", "name": "opens_at",
  "config": { "minDate": "09:00", "maxDate": "17:00", "seconds": false,
              "minutesStep": 15 } }
```

**The bounds are published exactly as the panel declared them, unnormalised.**
Filament's `getMinDate()`/`getMaxDate()` evaluate the declared value and
nothing more, so a `time` field bounded with `->minDate('09:00')` publishes
`"09:00"`, while one bounded with a Carbon publishes `"2026-01-01 09:00:00"`.
Normalising a bare time into a full datetime would invent a date the panel
never chose, and normalising the other way would discard one it did.

**A client must not read a `time` bound with a plain ISO date parse.** In Dart,
`DateTime.tryParse("09:00")` returns `null` — so the obvious "malformed bound
reads as no bound" rule silently deletes a bound the panel really set and then
offers the user times the server rejects. Read a `time` node's bounds (and its
value) as a clock time, falling back to a datetime parse for the Carbon shape.
A bound that arrives and cannot be read is a contract violation worth
reporting, and is not the same event as a bound that was never declared.

**`seconds` decides the value's format**, matching `TimePicker`'s own `H:i` /
`H:i:s`: a `time` value is `"HH:mm"` when `seconds` is false and `"HH:mm:ss"`
when it is true. Note that `seconds` defaults to **true** in Filament — an
unconfigured picker publishes `true`, not `false`.

**`hoursStep` / `minutesStep` / `secondsStep` publish only when the evaluated
value is greater than 1** — absent means 1, Filament's default — and only on
`datetime` and `time` nodes; a `date` node has no time grid and never carries
them. A throwing step closure degrades that one key, like every other
closure-backed read. These keys are **advisory** — the same precedent as the
repeater's `reorderable`: the contract states what the field was configured
with, for a host rendering its own picker. Nothing enforces a step server-side,
and the stock Material pickers have no step grid, so a client should neither
infer enforcement from these keys nor snap a picked value — snapping would make
mobile stricter than the panel it mirrors.

**Bounds are hints, not validation — final ruling, by web parity.** Filament's
web panel does not enforce `minDate`/`maxDate` server-side either (its JS
picker restricts choice; no validation rule), so a mobile client that enforced
them would be stricter than the panel it mirrors. The picker's
`firstDate`/`lastDate` clamp is the client-side enforcement. The server refuses
an out-of-range value only if the panel also declared a rule saying so, so a
client that ignores the hints will not be corrected on submit.

**Not on the wire, deliberately — final rejections, not deferrals:**

- **`disabledDates`** is closure-evaluated, which on this contract means
  schema-generation time, and `/schema` is ETag-cached: a dynamic list such as
  `[now()->addDays(2)]` would freeze at build time and keep answering, silently
  stale, until the panel code changed. A hint that goes silently stale is worse
  than no hint. The day a host asks, the answer is per-record evaluation on
  `/state` — no host has asked.
- **`firstDayOfWeek`**: the stock Material date picker derives it from the
  device locale and takes no parameter, so publishing it would state a
  capability no client of this contract can honour.
- **`timezone` and `displayFormat`**: unchanged from the bounds release — the
  client renders device-local and in its own format.

## The color field

A `ColorPicker::make('accent')` publishes a `color` node:

```jsonc
{ "type": "color", "name": "accent", "config": { "format": "hex" } }
```

`ColorPicker` exposes exactly one accessor, `getFormat()`, so `format` is the
only key `config` ever carries. It is a **closed set** — `hex`, `hsl`, `rgb`
or `rgba` — and anything else (a host override the walker cannot foresee, or
a future Filament version) normalises to `hex`, Filament's own default:
a client cannot act on a fifth value.

**The value itself is never on this key.** It travels as a plain string, in
the declared format, through the ordinary `default`/`/state` paths every
other field already uses — `config` says only how to read that string, never
what it holds.

**No format conversion, anywhere.** A panel declaring `rgb` gets `rgb` back
from the client, byte for byte where the user did not edit it; the client
parses all four shapes but never rewrites one into another.

**No graphical picker on the wire either.** The client renders a text field
holding the value in its declared format, with a live swatch beside it that
tracks the last value it could parse — chosen over a hand-rolled HSV picker,
whose colour maths is easy to get subtly wrong and hard to test.

## The tags field

A `TagsInput::make('labels')` publishes a `tags` node:

```jsonc
{
  "type": "tags",
  "name": "labels",
  "label": "Labels",
  "config": { "separator": ",", "suggestions": ["urgent", "billing"] }
}
```

**The value is a `List<String>` on the wire in every case — separator or
not.** A configured `separator` changes only what the *panel* stores
(Filament's own dehydration joins the tags into `"a,b,c"` for that column);
the join happens server-side at write time, so a client neither builds nor
parses the delimited form. Sending the delimited string back is refused
with a `422`, not silently stored.

**The one exception, and it is a shape exception:** on a *relation* payload
(`/relations/{name}`) the server must know which resource declares the child
model's form before it can un-join that column, and it refuses to guess —
`ResourceRegistry::findByModel()` answers only when **exactly one** opted-in
resource maps to the model. With zero, or with several (an ordinary panel
shape — two resources over one model, e.g. a full one and a compact one),
the field falls back to its **stored representation**, which for a
separator-configured field is the delimited `String`, not a list. It cannot
be split blind: the separator is declared per-resource, so splitting `"a|b"`
on a guessed `,` would publish one wrong tag rather than two right ones. A
client reading a `tags` field off a relation row must therefore tolerate a
`String` there, and the safe reading is "one tag" — never a crash, never a
`List` cast that throws. Every non-relation seam (`index()`, `show()`, and
both write response bodies) resolves the owner from the request itself and
is unaffected.

`config.separator` is published so a renderer can say what the panel does.
It is **not** an instruction. `config.suggestions` is a convenience list — a
client must still accept any tag the user types.

**Per-tag rules are keyed by index.** A `->nestedRecursiveRules(['max:20'])`
on the field is enforced per element, and its `422` comes back keyed
`labels.0`, `labels.1`, … — put the message on the offending tag, never on
the whole field. (The published `rules` block on the node describes the
field itself, as for every other type; the per-tag bound is enforced by the
server and surfaces only in the `422`.)

**Not on the wire, deliberately:** `splitKeys`, `tagPrefix` and `tagSuffix`.
A tag commits on submit only, and prefixes/suffixes are presentation this
contract does not reproduce.

## The tags_entry component (read-only)

A `Filament\Infolists\Components\SpatieTagsEntry::make('tags')` (from
`filament/spatie-laravel-tags-plugin`) publishes its own `tags_entry` node —
not folded into `tags`, because it never accepts input:

```jsonc
{
  "type": "tags_entry",
  "name": "tags",
  "label": "Tags"
}
```

**Same wire shape as the form's `tags` field: a `List<String>` of tag names,
never a delimited string.** No `config` at all — a `tags_entry` carries
neither `separator` (it has no column to implode into; Filament's own
`saveRelationshipsUsing` writes a Spatie tags field, never a dehydrated
string) nor `suggestions` (there is nothing to type into a read-only
control). A client renders it exactly like the ordinary display of a `tags`
field's value, minus the editing affordance — the existing chip/badge
treatment, with no delete control.

**Type-scoped the same way the form field is.** `SpatieTagsEntry::type('x')`
scopes the published list to that Spatie tag type, the same `->type()` gate
`SpatieTagsInput` reads; the default (no `->type()` call) publishes every tag
regardless of type, an `AllTagTypes` instance under the hood.

**The record payload folds a form field and an infolist entry sharing one
name into a single path.** A resource whose form declares
`SpatieTagsInput::make('tags')` and whose infolist *also* declares
`SpatieTagsEntry::make('tags')` publishes exactly one `tags` key on the
record — the same union `tags`'s form-only precedent no longer holds now
that an infolist half exists (see this contract's `## The tags field`
section, which the union applies to identically). An infolist-only path —
one the form never declares at all — still reaches the record payload: the
server folds both containers before serialising, the same two-halves
treatment already established for medialibrary's `image_entry`/`file`
pair.

## The keyvalue field

A `KeyValue::make('meta')` publishes a `keyvalue` node. The value is a
`Map<String, String>` on the wire, always:

```jsonc
{
  "type": "keyvalue",
  "name": "meta",
  "label": "Metadata",
  "config": {
    "addable": true,
    "deletable": true,
    "editableKeys": true,
    "editableValues": true,
    "keyLabel": "Key",
    "valueLabel": "Value",
    "keyPlaceholder": null,
    "valuePlaceholder": null
  }
}
```

**The four boolean keys are client hints, not a server-enforced
permission.** `addable`/`deletable` say whether a renderer should offer an
Add/Remove control per pair; `editableKeys`/`editableValues` say whether a
row's key or value cell should render as an input at all versus plain,
unfocusable text. All four default to `true`, matching
`Filament\Forms\Components\KeyValue`'s own vendor defaults. **A client should
honour all four by *not drawing* the corresponding control, but must not
assume the server would refuse a payload that ignores them** — the field's
own rule is `array` and nothing narrower, so a crafted request can add,
remove or rename a key an `editableKeys: false` gate says it should not be
able to, and the write path persists it verbatim. This is the same class of
statement `contract/README.md` already makes about the repeater's
`addable`/`deletable` — a client affordance, not a server rule — extended
here to all four gates rather than two.

**Not on the wire, deliberately:** reordering. The repeater publishes
`config.reorderable`; `keyvalue` publishes nothing equivalent, because this
package has never offered reordering for either array-valued field and the
two must not disagree about that for no stated reason.

## The repeater field

A JSON-column `Repeater::make('items')->schema([...])` publishes a `repeater`
node carrying its item template as `children` — the **same shape** a layout
container (`section`, `grid`, `tabs`, `fieldset`) already uses, so a client's
existing recursive walk renders it with no special-casing:

```jsonc
{
  "type": "repeater",
  "name": "line_items",
  "label": "Line items",
  "children": [
    { "type": "text", "name": "sku", "label": "SKU", "rules": {"required": true, "max": 20} },
    { "type": "number", "name": "qty", "label": "Qty", "rules": {"numeric": true} }
  ],
  "rules": {"required": true, "min": 1, "max": 5},
  "config": {
    "addable": true,
    "deletable": true,
    "minItems": 1,
    "maxItems": 5,
    "itemLabel": null,
    "reorderable": false,
    "readOnly": false
  }
}
```

Two things a client must get right, both load-bearing:

- **`children` is the template, published once — never once per stored
  row.** A repeater with three saved rows still publishes exactly one
  `children` array; a client renders it once per entry in the field's own
  value, which travels separately as a `List<Map>` under `name` like any
  other field's value. `itemLabel` is always `null` this slice — Filament's
  `getItemLabel($key)` needs a real row's own state, and there is no row yet
  at schema-generation time, only the template.
- **`reorderable` may be `true` while a client offers no reordering at
  all.** Publishing it keeps the contract honest for a host that renders its
  own repeater UI; **this package's own Dart client always acts on it as
  `false`**, regardless of what the field publishes. A client reading this
  contract should not infer "the server supports reordering" from this flag
  alone — it names what the *field* was configured with, not what any given
  renderer offers.

**Each child node carries its own `rules` as usual**, so a client can
pre-validate a row with the exact machinery it already has for a top-level
field — no per-item shape to invent. The *server's* rules for those children
travel under starred paths (`line_items.*.sku`), which is a Laravel-native
shape a client never has to construct itself: a `422` for a bad row comes
back keyed `line_items.0.sku`, the real row index, and a client renders that
error against the same row.

**Two shapes publish `config.readOnly: true`**, and a client treats both
identically — the field renders, its stored rows render, and nothing
about it can be edited or submitted:

- **A nested repeater** — one inside another repeater's item template. Its
  rows still travel, as part of the outer array's value; they simply cannot
  be edited, because a nested row's `422` comes back keyed
  `outer.0.inner.1.x` and no renderer in this contract has a field to put
  that against.
- **A repeater whose item template holds a child that would not
  round-trip** — a `Hidden`, a component type the server cannot map, or a
  `disabled()`/never-dehydrated field. The row array is written whole and
  validated per path, so a child with no rule of its own has its key deleted
  from every row on save. The server refuses the whole field instead. Its
  stored rows are still served on `GET` and must still render.

**A relationship repeater** (`Repeater::relationship()`) is a third case,
and since P9 it is **editable**: it writes through Filament's own
`saveRelationships()`, so it publishes `readOnly: false` like an ordinary
repeater. Two things still set it apart:

- **It has no column of its own**, so its name is absent from the record
  payload on `GET` — its rows are child records, surfaced through the
  resource's `relations` array and the relation endpoint, not through the
  parent's `data`. (Publishing them under the field's name would also leak
  full child rows past the card's whitelist for the common idiom that names
  the field after the relationship.)
- **Every save replaces the whole set.** Filament matches submitted rows to
  existing children by `record-{id}` state keys, which this contract's
  plain-list wire shape cannot express, so a save is delete-all-then-
  recreate. A client that submits the field at all must submit every row
  the user means to keep; an untouched field is simply not submitted (the
  client's dirty tracking already guarantees this).

See `laravel/filament-mobile/README.md`'s Repeater section for the
write-path refusal and the `RuleExtractor`/`WritableNames` name-space split
these shapes depend on.

**`addable`/`deletable` are client affordances, not server rules.** Only
`minItems`/`maxItems` are enforced server-side. A client should honour
`addable: false`/`deletable: false` by not drawing the control, but must not
assume the server would refuse a payload that ignores them.

**`config.readOnly` is always present on a repeater node, both ways round** —
`false` for an ordinary JSON-column repeater, `true` for a refused one. It is a gate, like `hidden` and `disabled`, and a gate is stated
explicitly rather than inferred from absence. A client should read an
*absent* `readOnly` as `true`: absence means a server predating repeater
support, and a client must never invent a capability the server did not
declare. Those two rules only agree while the server states the ordinary
case too: P6c's first cut published the key for the refused case alone, and
every ordinary repeater rendered inert in a client honouring the absence
rule — which is why "always present" is part of the contract, not an
implementation detail.

## The `relations` array on a resource

Each resource block in `/schema` carries a `relations` array — **always
present**, `[]` for a resource with none, never an absent key on a current
server:

```jsonc
{
  "key": "banners",
  "label": "Banners",
  "card": { "title": { "field": "name" }, "subtitle": { "field": "status" } },
  "recordKey": "id",
  "search": { "enabled": false },
  "sorts": []
}
```

- **`key`** is the relationship name (`getRelationshipName()`), and it is
  what `GET /{resource}/{record}/relations/{key}` is addressed by — never a
  Filament relation-manager class name.
- **`label`** is the manager's own title, falling back to a humanised `key`.
- **`card`** is the same card shape a resource's own list card already
  uses (`title`/`subtitle`/`badges`/`meta`), derived from the manager's
  first two columns unless the host overrides it.
- **`recordKey`** is the **related** model's own route key — not the
  parent resource's `recordKey`, and not always `id`. Parse each row in
  this relation's endpoint by `recordKey`, the same way the resource's own
  list is parsed by its own `recordKey`.
- **`search` / `sorts`** are the same shapes a resource block publishes —
  `{ "enabled": bool }` and a list of `{ "key", "label", "default",
  "direction" }` — declared per relation on the server, never read off the
  relation manager's table. **Always present on a current server**: an
  undeclared relation publishes `search: { "enabled": false }` and `sorts:
  []`. An *absent* key means a server predating P11 — read absent `search`
  as disabled and absent `sorts` as `[]`, never as an error: the same
  absence rule the `relations` array itself already carries.

**`GET /{resource}/{record}/relations/{relation}`'s envelope is identical to
a resource list's** — `{data, meta: {current_page, last_page, per_page,
total}}`, the same card fields on each row. A client that already renders
`GET /{resource}` can render this endpoint with no new parsing, keyed by
`recordKey` above instead of the parent resource's own.

**The endpoint answers `?search=`, `?sort=` and `?direction=` exactly as the
resource index answers them** — LIKE with `!`-escaping inside a single where
group, an unknown `sort` key is a **`422`**, a non-string parameter
(`?sort[]=x`) is the same `422` the index promises, and a declared default
sort applies when `?sort=` is absent. Validation runs **after** the full
gate sequence: a `403` or `404` always wins over a `422`, because a
validation error must never leak whether a relation exists for a record the
caller cannot see. Against a relation that declares nothing, `?search=` is
inert but an *undeclared sort key still 422s* — the sort parameter claimed
a capability, the search parameter did not. Filters stay out, permanently:
the same ruling the resource level already publishes `'filters' => []`
under.

**An absent `relations` key means a server predating P6d — read it as no
relations, never as an error.** A client must not distinguish "the server
sent `relations: []`" from "the server is old enough to not send the key at
all"; both mean the same thing to a renderer: nothing to show.

**A relation this endpoint refuses — most commonly one whose table narrows
its own query with `modifyQueryUsing()` — is absent from `relations`
entirely**, not published with some disabled marker, and its endpoint 404s
rather than 403s: a 403 would suggest the relation might appear for
someone else, and this one will not appear for anyone. See
`laravel/filament-mobile/README.md`'s Relations section for the full
refusal reasoning, the authorization gates, and why gate 2 runs
under guard impersonation.

**A relation whose `card` fills no slot is not a relation.** The server does
not publish one, and a client reading a `card` that is absent, null or empty
must **drop the relation**, not render a section with an all-null layout: it
would be a heading over rows that render nothing, which is the disabled
corpse this contract does not ship. Every other missing required field on a
relation node already drops it; this is the same rule.

## Writing relation rows (`resource`, `POST`/`PUT`/`DELETE`)

A relation node gains one more key when its rows are writable:

```jsonc
{
  "key": "tags",
  "label": "Tags",
  "card": { "title": { "field": "name" } },
  "recordKey": "name",
  "resource": "tags"   // the child resource's own key — only when writable
}
```

**`resource` is present only when exactly one registered resource owns the
related model.** Zero owners means the panel never gave the child a resource
(several means the server cannot know which form and gates to use) — either
way the relation is read-only, the key is absent, and the write endpoints
**404** rather than 403: an unpublished capability is indistinguishable from
an unknown one. An absent `resource` must also be how a client reads a
server predating P9 — never guess writability from anything else.

When it is present, the relation endpoint takes three more verbs, addressed
exactly like the read:

- **`POST /{resource}/{record}/relations/{relation}`** creates a child
  through the relationship (`$relationship->create(...)`), answering `201`
  with the created row in the relation envelope's row shape.
- **`PUT .../relations/{relation}/{child}`** updates one child, answering
  `200` with the updated row. `{child}` is the **related** model's route key
  — the node's own `recordKey`, here `name`, not always `id`. A child that
  exists but belongs to a *different* parent **404s**: the relationship
  boundary is the addressing scheme.
- **`DELETE .../relations/{relation}/{child}`** deletes one child, answering
  `200` with the deleted row. (Deliberately not a mirror of a resource's own
  `destroy`, which answers `204` with no body — a relation row vanishing
  from a rendered list is confirmed by the row itself.)

The child resource's own **`form`, `rules` and `permissions`** drive all of
this: the create/edit form a client renders is the child resource's `/schema`
form verbatim, a `422` comes back keyed by the child's field names exactly
like a resource write, and each verb is gated — the parent's `view` first,
then the child resource's `create` (class-level), `update` or `delete`
(per-record) — answering `403` on denial. Attach/detach of existing records
(BelongsToMany pivot operations) is **not** exposed this slice; create,
update and delete only.

## The `rich_entry` type and the `__rich` sibling

An infolist node with `"type": "rich_entry"` names a column whose stored
value is TipTap/ProseMirror-convertible markup, not plain text. It joins the
walker's other refined types (`badge_entry` is the existing example): no
Filament class maps to it directly, and `/schema` alone tells a client which
of a resource's entries are rich.

The node itself carries nothing extra — no document lives on `/schema`,
which publishes an empty form snapshot and has no record to convert. The
document travels on the **record** payload instead, `GET
/{resource}/{record}` and each row of `GET /{resource}`, as a flat sibling
key beside the column's own raw value:

```jsonc
{
  "id": 1,
  "body": "<p>Hello <strong>world</strong></p>",
  "body.__rich": {
    "doc": { "type": "doc", "content": [/* ProseMirror tree */] },
    "text": "Hello world"
  }
}
```

- **`data.<path>` is unchanged** — still the raw string, still what a form
  prefill reads. This slice never touches the write path, so a client that
  ignores `rich_entry` entirely keeps working exactly as it did before this
  type existed.
- **`<path>.__rich.doc`** is the ProseMirror document the detail screen
  renders: `RichEditor`'s own closed vocabulary of ten node types (`doc`,
  `paragraph`, `text`, `heading`, `bulletList`, `orderedList`, `listItem`,
  `blockquote`, `horizontalRule`, `image`) and six marks (`bold`, `italic`,
  `link`, `strike`, `underline`, `code`). A future node or mark type outside
  this list is possible — a client should degrade it, not choke on it — see
  the Dart README's Rich text section for the documented degradation.
- **`<path>.__rich.text`** is the same document flattened to plain text —
  tags stripped, entities decoded, whitespace collapsed — the shape a list
  card reads instead of walking the document.
- **Absence means nothing to convert, never an error.** A `null` or empty
  column, or one whose conversion failed server-side, publishes `<path>`
  with its ordinary value and simply carries no `.__rich` key at all — never
  an empty `{}` or a `null` in its place. A client reading `rich_entry` must
  fall back to the raw `<path>` value whenever the sibling is missing, the
  same fallback every other refined type already needs for a server that
  predates it.
- **A card slot bound to a rich column may carry the sibling on `GET
  /{resource}` too, or it may not**, and the two cases are both legitimate:
  the sibling appears there only for a column the resource's *model*
  declares rich (`HasRichContent`), never for one that is rich only because
  one infolist entry called `->prose()` — that call governs the infolist
  entry, not the list row, and the list endpoint builds no infolist to
  consult it. See `laravel/filament-mobile/README.md`'s Rich text section,
  "Cards", for the full reasoning and the `doctor` diagnostic that names
  this combination.

## `ETag` / `If-None-Match` on `/schema`

`GET /schema` sends a **weak** `ETag` (`W/"<sha1 of the built document>"`) on
every response, computed before `_warnings` is attached — `_warnings` is
dev-only and not part of the contract, so folding it into the hash would
move the ETag between environments for an otherwise-identical document.

A request that sends a matching `If-None-Match` gets back a **`304` with no
body** — never a `200` with an empty object, and never a body a client
should attempt to parse. The `ETag` header is still present on the `304`.
`If-None-Match` is read tolerantly: a comma-separated list, either the weak
or strong form of the value, and `*` (unconditional match, RFC 7232 §3.2).

Neither golden fixture (`panel.json`, `laravel-panel.json`) is affected —
the `ETag` is a response header, not a document field, so the body a
client parses on a `200` is unchanged.

## Reading `rules.numeric` and `rules.messages` as a client

- **`rules.numeric`** answers "does Laravel compare `min`/`max` as a VALUE or
  as a character LENGTH?", and is deliberately independent of the node's
  `type`. A `->email()->numeric()` field still renders as an `email` node —
  `refineType()` picks the render type by checking `isEmail()` before
  `isNumeric()` — but its bound is a value, not a length, and only `rules`
  says so. Key the length-versus-value decision off `rules.numeric`, never off
  `type`; using `type` blocks a submission the server would accept, which is
  the one thing a client hint must never do.
- **`rules.messages`** is a per-rule map (`{"required": "...", "max": "..."}`)
  in the panel's locale, generated through the same Laravel validator
  translation a `422` for the same submission would produce. A rule with no
  entry there is not an error — the server simply had no opinion — and the
  client falls back to its own `FilamentStrings` for that rule. Prefer a
  published message when one exists; a host that translates nothing still
  gets a hint in the panel's own language rather than the client's default
  English.

## `record-payload.json` and `relation-list.json`

Two more generated goldens, both written through their real endpoints and
read by the Dart suite (`record_payload_contract_test.dart`), closing the
same loop `laravel-panel.json` closes for `/schema`:

- **`record-payload.json`** — `GET /{resource}/{record}` (Laravel's
  `RecordSnapshotTest`, regenerate with `UPDATE_SNAPSHOTS=1`). Beside the
  rich-text sibling it was created to pin, its `data` carries a JSON-column
  repeater's stored rows (`line_items`, a list of maps) — the one
  record-payload shape no golden pinned before.
- **`relation-list.json`** — `GET /{resource}/{record}/relations/{key}`
  (`RelationSnapshotTest`, same regeneration). Its rows carry **no `id`,
  deliberately**: the fixture child model's route key is `name`, so each
  row's record key IS its `name` — live proof of the rule that a relation
  row is parsed by the relation's own `recordKey`, never an assumed `id`.
  Do not "fix" the missing `id`.

## `media-record.json` and Spatie medialibrary fields

A third generated golden, same family as `dashboard.json` and
`record-payload.json`: `GET /{resource}/{record}` through a fixture resource
(`GalleryResource`) built on `spatie/laravel-medialibrary`'s Filament
components — a `SpatieMediaLibraryFileUpload` multiple collection (`photos`)
and single collection (`cover`), the latter also shown by a
`SpatieMediaLibraryImageEntry`. Laravel's `MediaSnapshotTest` generates it
(`UPDATE_SNAPSHOTS=1 vendor/bin/pest tests/Feature/MediaSnapshotTest.php`);
the Dart contract test reads it beside `record_payload_contract_test.dart`.

**`record-payload.json` stays media-free, deliberately.** Its fixture
(`RichResource`) has no `HasMedia` model, so it keeps answering "server
without this feature" — the same role `panel.json` plays for `direction` and
`laravel-panel.json`'s registered-resources default plays for `galleries`
(`GalleryResource` is never in `TestCase`'s shared list, for exactly that
reason — see its own docblock). A media field's wire shape is inseparable
from Spatie's own `Media` model, so — like the dashboard — there is no useful
hand-written fixture to maintain separately from what the real endpoint
actually emits.

A media path publishes two flat sibling keys on `data`: the path itself
carries the raw uuid (a scalar for a single collection, a `List<String>` for
`->multiple()`), and `<path>.__media` carries one item per file —
`{uuid, url, thumbUrl, name, size, mime}`, `thumbUrl` `null` when the field's
model registers no matching non-queued conversion. See
`laravel/filament-mobile/README.md`'s medialibrary section for the read and
write paths in full.

**Determinism: every uuid, url and file size on this payload is runtime- or
environment-random**, unlike every other generated golden here — a fresh
uuid per test run, urls built from `APP_URL` plus that uuid, a PNG's exact
byte size varying with the host's GD/libpng build. `MediaSnapshotTest`
normalises all three through one shared function
(`normaliseMediaPayload()`, applied identically when the golden is written
and when it is asserted against) before either encodes the payload:

- each real uuid becomes `"uuid-1"`, `"uuid-2"`, … in encounter order across
  the `data` array's `*.__media` siblings, and every occurrence of that uuid
  — the raw `photos`/`cover` value and the matching `__media` item alike —
  maps to the same placeholder, so which media is which still survives.
- `url` becomes `"https://media.test/{n}/{name}"` and a non-null `thumbUrl`
  becomes `"https://media.test/{n}/thumb-{name}"`, `{n}` being that media's
  placeholder index and `{name}` its already-deterministic real file name.
- `size` becomes a fixed `100` for every item.

## `rules.url`, `rules.regex` and `rules.confirmed`

All three are emitted by the server since the A6 slice, closing the last
one-sided corner of the rules contract (Dart parsed them from the start):

- **`rules.url`** is `true` when the field declared `->url()`.
- **`rules.regex`** is the declared pattern **verbatim** — the client
  re-matches it pre-submit, and a rewritten pattern could disagree with the
  server's, so the server never rewrites it.
- **`rules.confirmed`** is `true` when the field declared `->confirmed()`.
  Filament has no accessor for it (the call registers an ordinary
  `rule('confirmed', ...)`), so the server detects it by scanning the
  field's own resolved rule list; a field whose rules cannot resolve
  headlessly simply publishes no hint. The rule compares against the value
  of the `<name>_confirmation` sibling — which real panels declare
  `dehydrated(false)`, so it is published `disabled` on `/schema` (the
  never-persist rule) while remaining part of the form's state client-side.

All three are also enforced by the write path itself — the published hint
and the eventual `422` come from the same declaration, as for every other
rule.
