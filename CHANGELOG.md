# Changelog

## Unreleased

- **The PHP floor is now `^8.4`, up from `^8.2`.** A **breaking** requirement
  change: `composer require` refuses on 8.2 and 8.3 rather than installing
  something that may not parse. Filament itself still allows `^8.2`, so this is
  this package's own floor, not one inherited.

  The reason is that the old floor was never actually held. A feature above the
  floor is a **parse error**, not a degradation — the file does not load, so
  nothing in it runs — and a typed constant (`const int`, PHP 8.3) shipped
  against the `^8.2` promise in v0.3.0, v0.4.0 and v0.5.0. It was invisible
  because the only PHP on the machine this is developed on is 8.4, and the
  matrix job that would have caught it could not start (see 0.6.1's CI note).
  Raising the floor to match the development version removes the gap rather
  than promising to watch it: the version you develop on is the version you
  promise. The CI matrix runs 8.4 only, and `scripts/lint-php-floor.sh` reads
  the floor out of `composer.json` so the two cannot drift.

  If you need 8.2 or 8.3, pin `gait/filament-mobile:^0.6.1` — that line is
  unaffected and its own floor stays `^8.2`.

## 0.6.1 — 2026-08-13

- **A relation a create cannot link is no longer published writable.**
  `store()` creates THROUGH the relationship so the foreign key is the
  parent's by construction — but that only holds for the types whose
  `create()` actually links: `HasOne`/`HasMany`/`MorphOne`/`MorphMany` (via
  `HasOneOrMany`) and `BelongsToMany`/`MorphToMany`. Discovery published any
  `Relation` subtype, so a `BelongsTo`, `MorphTo`, `HasManyThrough` or
  `HasOneThrough` relation manager whose child model had exactly one mobile
  resource was published writable, and `Relation::__call` then forwarded
  `create()` to the query builder: a `201` for a row created **unrelated to
  the parent**, which the client's own refresh would not then find. A
  `HasManyThrough` reaches past its intermediate table; a `BelongsTo`'s verb is
  `associate()`, which writes the PARENT, not a child.

  The `resource` key is withheld for those types, so all three write endpoints
  `404` on the read path's own ruling, and the READ path is untouched —
  reading a `BelongsTo` relation stays perfectly available. Decided in
  `RelationDiscovery`, not the controller, so the published capability and the
  endpoint cannot disagree; `doctor` reports the relation TYPE as the reason
  **before** the owner-count one, because naming a missing resource would send
  the author looking for something that already exists.

## 0.6.0 — 2026-08-13

- **Relation writes.** A published relation is no longer read-only by
  definition. `/schema`'s relation nodes gain a `resource` key — the child
  **resource's** key — present only when exactly one registered mobile
  resource owns the related model (`ResourceRegistry::ownersOf()`): zero
  owners or several and the key is absent and the relation stays a read
  path. One resolution drives both the key and the endpoints, so the schema
  and a `404` can never disagree. New endpoints:
  `POST /{resource}/{record}/relations/{relation}`,
  `PUT .../relations/{relation}/{child}`,
  `DELETE .../relations/{relation}/{child}`. A write against a relation with
  no single owner — or one never published — is a **404, not a 403**, the
  read path's own ruling: what this API will never serve does not exist.
  The form is the **child resource's own**, reused whole, through the same
  machinery `store()`/`update()` run — `SettledSchema`, rules as the
  mass-assignment whitelist, defaults under the payload, the `TagSeparators`
  mirror, the relation pass — now extracted from `MobilePanelController`
  into `src/Write/RecordForm.php`, the one home both controllers share.
  Gates: every gate the read endpoint applies, then the child model's own
  `create` (class-level — no child record exists yet), `update`/`delete`
  (against the loaded child). Create goes **through the relationship**, so
  the foreign key is the parent's by construction; `{child}` is the related
  model's own route key resolved *through the relationship*, so an id that
  exists under a different parent is a 404, never a cross-parent write.
  Status codes are `201`/`200`/`200` — delete returns the deleted row,
  serialized *before* the delete, deliberately not `destroy()`'s 204: the
  relation client holds a list it must reconcile. A validation failure is a
  `422` keyed by the child's own field names. **Attach and detach are
  deliberately not exposed** — pivot operations are a different gesture with
  a different authorization question. `doctor` names a published relation
  whose writes are off, distinguishing zero-owner from several-owner,
  because the fixes differ. The Dart client half (`RelationDescriptor.resource`,
  `createRelation`/`updateRelation`/`deleteRelation`, `RelationSubmitTarget`,
  the permission-gated `RelationListScreen` affordances) is
  `filament_mobile`'s own 0.8.0.
- **A relationship repeater is editable.** `Repeater::relationship()` — the
  last repeater shape published `readOnly: true` on principle — writes
  through Filament's own `saveRelationships()`: the repeater registers its
  own `saveRelationshipsUsing()` (`Repeater::saveToRelationship()`), and the
  write path's relation pass reaches it unchanged, the same call Filament's
  own `CreateRecord`/`EditRecord` make. The caveat is row identity and it is
  pinned in `RepeaterWriteTest`: keyless state leaves no row to diff
  against, so **every save is delete-all-then-recreate**. The field still has
  no column of its own, so the attribute pass never reads one; its rows are
  published by a pass of their own instead — read off the relationship and
  **projected onto the item template's declared fields**, so a child's `id`,
  timestamps and pivot stay off the wire like any other undeclared column.
  Zero rows publish `[]`, never absence: "no rows" is an answer, and a client
  that cannot distinguish it from "unknown" has to guess. Two guards make
  that safe, and both are pinned: the record payload carries the rows so the
  edit form has something to seed from, and the write path refuses to read a
  present `null` as a clear — only an explicit `[]` clears. Without either,
  a writable field the client could not see submitted `null` and destroyed
  every child row behind a `200`. The remaining read-only shapes are the two
  that were never about relationships: a nested repeater, and an item
  template holding a child that would not round-trip. A relationship gate
  that cannot answer (a throwing `->relationship()` closure) stays refused,
  fail closed, and is the one relationship shape `doctor` still reports.
- **Rich-text conversion is memoised per request.** The P6e known weakness —
  conversion uncached per request — is closed by a per-instance memo on
  `RecordSerializer`, keyed by the raw string, nulls memoised (a value whose
  conversion degrades does not pay for its failure twice); one serializer
  per request makes the memo's lifetime the request's. The
  `RelationDiscovery::for()` half of the pass P6e envisioned was measured
  and deliberately **not** done: the split already runs exactly once per
  resource per request at every HTTP entry point, so there was no redundancy
  to remove.
- **`url`/`regex`/`confirmed` are published and enforced.** Three rules the
  write path never re-derived — a `->url()`, `->regex(...)` or
  `->confirmed()` field 422'd on the web panel and sailed through mobile,
  the one direction this package's validation can never drift. The walker
  publishes `rules.url: true`, `rules.regex` and `rules.confirmed: true`;
  `RuleExtractor` emits the matching Laravel rules.

  `rules.regex` travels **undelimited** — `^[a-z0-9_]+$`, not
  `/^[a-z0-9_]+$/` — because a client regex engine takes a bare pattern and
  compiles the delimited form into one that matches *nothing at all*: the
  leading `/` becomes a literal and the `^` behind it asserts a start of
  input already consumed. Published verbatim, a `->regex()` field would be
  permanently unsubmittable from the phone for values the server accepts, and
  the "pattern Dart cannot compile fails open" escape never fires because the
  pattern compiles fine. The server keeps its delimiters, since Laravel's
  `regex:` rule requires them. A pattern carrying **flags** publishes no hint
  at all rather than a stripped one: with no inline modifiers on the client,
  `/x/i` published as `x` would be *stricter* than the server, the same bug
  mirrored — so it fails open and the server stays the only judge.

  `confirmed` has no Filament accessor — `->confirmed()` registers an
  ordinary `rule('confirmed', ...)` — so both sides detect it by scanning
  the field's own resolved `getValidationRules()`. The walker's scan is a
  new **silent probe** (`declaresConfirmed()`), deliberately not the guarded
  `read()`: a component whose rule list cannot resolve headlessly throws as
  an ordinary event here, and a warning about a probe is noise. The
  confirmation **sibling** is published as a field the client fills, which is
  the one exception `dehydrated(false)` now carries: Filament's own idiom
  never persists the second field, but the user still has to type it, and
  publishing it disabled and unwritable made every `->confirmed()` field
  permanently `422` — the payload omitted the key the rule reads. It stays
  out of the persisted set for the reason it always was: no rule of its own,
  so no validated key, so no column write. An ordinary `dehydrated(false)`
  field that confirms nothing is still published locked. **A
  rule-message translation failure now degrades per-field** — a throwing
  translator costs that field's `messages` map (the client falls back to its
  own strings per rule), never the component, and never the document; a bare
  test-double translator no longer takes down `/schema`.
- **Remote options work inside a repeater row.** `OptionsController::findSelect()`
  now descends *through* a repeater into its item template: the client
  renders a row's select off the template and asks for it by its bare child
  name, so a lookup that stopped at the repeater's border 422'd a node the
  schema itself published with an `optionsUrl`.

## 0.5.0 — 2026-08-08

- **`ColorPicker` and `TimePicker`.** Two more previously-unmapped field types
  are editable on the phone. `color` publishes the format the panel declared —
  a closed set of `hex`, `hsl`, `rgb`, `rgba`, with anything else and a
  throwing closure both normalising to `hex`. `time` is its own type, and
  because `TimePicker` is a five-line subclass of `DateTimePicker` it **widens**
  the date branch rather than copying it.
- **Date bounds reach the client at last.** `date`, `datetime` and `time` now
  publish `config: {minDate, maxDate, seconds}` from `getMinDate()`,
  `getMaxDate()` and `hasSeconds()`. This closes a gap rather than adding a
  feature: the Flutter client has parsed those bounds and passed them to its
  picker since the day it was written, and the server had never sent them, so
  the code was wired and dead in every host.
  A bound is published **verbatim** — `->minDate('09:00')` publishes `"09:00"`,
  a Carbon publishes `"2026-01-01 09:00:00"` — because normalising a bare time
  into a full datetime would invent a date the panel never chose.
- **Bounds are hints, not rules.** Publishing one does not create a validation
  rule; the server still refuses an out-of-range value only if the panel
  declared a rule saying so.
- Fixture note for maintainers: `MarkdownEditor` is now the fixtures'
  stand-in for "a deliberately unmapped component", inherited from
  `ColorPicker` when that became mapped. **Mapping `MarkdownEditor` will break
  `RepeaterRulesTest` and `DoctorCommandTest` until they are given a new
  stand-in** — the fixtures' meaning depends on the component being
  unsupported, which is invisible from the fixtures themselves.

## 0.4.0 — 2026-08-07

- **Radio, tags and key/value.** Three previously-unmapped Filament field
  types are editable on the phone: `radio` (`Radio::make()`), `tags`
  (`TagsInput::make()`) and `keyvalue` (`KeyValue::make()`) join
  `ComponentTypeMap::MAP`. **Radio** reuses `Select`'s own `Concerns\HasOptions`
  — same trait, same `getOptions()` — so the walker's existing option reader
  needs no change; only the rendering is new. One hazard found and closed: the
  select/multiselect inline-cap branch falls back to publishing
  `config.optionsUrl` for async search once past `options_inline_max`
  options, which a radio can never call (no search affordance, nothing to
  post a query to) — an over-cap radio now always inlines its full option
  list, guarded `$type !== 'radio'` in `SchemaWalker::config()`. No
  `RuleExtractor` change: `select` produces no `in:` rule for any
  option-bearing field today, so there was no existing parity to give
  `Radio`. **Tags** is always a `List<String>` on the wire, config
  `{separator, suggestions}`; `splitKeys`/`tagPrefix`/`tagSuffix` are
  withheld. Its per-tag rules (`->nestedRecursiveRules(['max:20'])`,
  `HasNestedRecursiveValidationRules::getNestedRecursiveValidationRules()`,
  never previously handled by this package) are extracted as `labels.*`
  through the same name-space split P6c's repeater established:
  `RuleExtractor` mints both `labels` and `labels.*`; `WritableNames`
  contributes only `labels`, because `Arr::has()`/`Arr::set()`
  (`Write\SettledSchema::reset()`) cannot express a starred name at all — the
  starred entry is inert here rather than destructive, since `labels` persists
  on its own, unlike a repeater's per-item names. **A real bug was found and
  fixed underneath this:** `MobilePanelController::isRuleNameAllowed()`
  admitted only the repeater's `name.*.child` shape
  (`str_starts_with($name, $allowed . '.*.')`), never `labels.*` (no trailing
  dot, no child segment) — so a per-tag rule was extracted, published on
  `/schema`, and silently dropped before `validate()` ran: a 21-character tag
  under `max:20` saved with a `200`. Now also admits
  `$name === $allowed . '.*'` exactly. A tags field whose nested-rule closure
  throws is refused wholesale — no rule, no writable name — rather than
  degraded like every other guarded read in this package, because the closure
  guards a constraint, not a hint. **The separator mirror**, the first place
  this package reproduces Filament's own dehydration: a `->separator(',')`
  field's submitted array is joined server-side, after `fillMissingPaths()`,
  before both `store()` and `update()` persist it — one function, both
  endpoints — so the column matches what the panel itself writes. The inverse
  read-side un-join lives in `RecordSerializer::hydrate()`, so all six
  serialize seams (`index()`, `show()`, the `store()` `201` body, the
  `update()` `200` body, `RelationController`'s rows, and any future one)
  share one answer instead of drifting independently; a related row's owning
  resource is resolved by `ResourceRegistry::findByModel()`, which returns
  `null` unless exactly one opted-in resource maps to the model — degrading
  to the **stored representation**, which for a separator-configured field
  is the delimited `String`, so a relation row is the one place a client
  must tolerate a `tags` field that is not a list (it cannot be split blind:
  the separator is declared per-resource, which is precisely what could not
  be resolved). This mirror is a stated reproduction, not a general
  capability: a future Filament change to `dehydrateStateUsing()` would
  silently diverge, and the test that would catch it asserts the stored
  column, not the response code. **Key/value** publishes
  `addable`/`deletable`/`editableKeys`/`editableValues` plus labels and
  placeholders, value a `Map<String, String>`; the getters read are
  `canEditKeys()`/`canEditValues()`, not the setter names
  `editableKeys()`/`editableValues()` — reading the wrong ones through this
  package's guarded reader would return `null` and fail open. All four gates
  are **client hints, not enforced by the write path**: `RuleExtractor`
  constrains the field to `array` and nothing more, so a crafted request can
  add, remove or rename a key a `false` gate says it should not — matching
  Filament itself, which never re-checks these flags on write either, but a
  real gap from `disabled`, which this package's write path does enforce.
  Known weaknesses, stated in the README: `splitKeys`/`tagPrefix`/
  `tagSuffix` and `Radio::isInline()` are ignored; key/value has no
  reordering (matching the repeater) and no key-uniqueness validation; its
  four gates are advisory, and an all-four-`false` field is effectively
  read-only today and could join `WritableNames` using the same machinery
  `disabled` already uses. **No break for any host**: three new component
  types joining `ComponentTypeMap::MAP` is additive, and nothing about the
  existing wire shape for any other type changed.

## 0.3.0 — 2026-08-07

- **Upload.** `POST /{resource}/upload` (multipart: `file` + `field`) makes a
  single-file `FileUpload`/`SpatieMediaLibraryFileUpload` field genuinely
  editable from a phone — previously always published `readOnly: true`.
  Gate order matches every other endpoint (resource 404 → `viewAny` 403 →
  field-resolution 403); `Upload\UploadFieldResolver` re-derives the field
  through the write path's own `SettledSchema`/`WritableNames` machinery and
  returns `null` — never throws — for every refusal (unresolvable, disabled,
  disabled ancestor, name resolving to multiple components, not an upload,
  multi-file), so every refusal is the identical bodyless 403 a probing
  client cannot distinguish. `getAcceptedFileTypes()`/`getMaxSize()` are
  read off the resolved component and enforced as real validation, with the
  MIME type sniffed from content (`mimetypes:`, never `mimes:`, which trusts
  the extension); a throwing constraints closure fails closed with a `422`.
  The stored filename's extension comes from the sniffed MIME clamped to a
  fixed `SAFE_EXTENSIONS` allow-list (`png`, `jpg`, `jpeg`, `gif`, `webp`,
  `pdf`) — anything else stores with no extension — so an uploaded polyglot
  is inert on disk regardless of what the client claimed it was. Storage
  goes through the component's own `getDiskName()`/`getDirectory()`/
  `getVisibility()`, not Filament's `saveUploadedFile()` (its signature
  requires a Livewire `TemporaryUploadedFile`, exactly the coupling this
  package avoids). `RuleExtractor` now admits a single-file field's rule
  (multiple stays withheld), so the returned path saves through the
  ordinary write path with no change to `store()`/`update()` — the form
  submits a stored path string, never bytes. `/schema` publishes
  `config.readOnly: false` plus `config.accept`/`config.maxSize` for a
  single-file field; multiple, and any field whose constraints closure
  throws, publish `readOnly: true`. `filament-mobile:doctor` reports
  multi-file fields informationally (non-zero would fail CI over a shape
  this slice simply doesn't support yet). Known weaknesses, stated in the
  README: orphaned files accumulate without host-side pruning, and
  multi-file remains unusable. This is P6a — the first of P6's six
  sub-projects.
- **Upload hardening** (final P6a review round). A non-string `field`
  (`field[]=...`) answers the same bodyless 403 as every other refusal
  instead of a 500. A throwing `FileUpload::multiple()` closure now fails
  CLOSED everywhere it is read: `/schema` publishes `readOnly: true` +
  `writable: false`, `RuleExtractor` withholds the rule (so `PUT` can
  neither write nor clear the column), and the upload endpoint refuses —
  previously the first two failed open while the resolver refused, offering
  a control whose every upload 403'd. A throwing
  `getDirectory()`/`getDiskName()`/`getVisibility()` closure answers the
  same 422 as a throwing constraint closure instead of a 500 after
  validation passed. A throwing `getMaxSize()` closure refuses outright
  with a 422 — it used to compile to `max:0`, which Laravel passes for a
  zero-byte file, letting an otherwise-unrestricted field store an empty
  file.
- **Dashboard.** `config('filament-mobile.widgets')` opts named
  `StatsOverviewWidget` and `ChartWidget` subclasses into a new `GET
  /api/mobile-panel/dashboard`, by class name, in publication order — never
  auto-discovered, the same "invisible until named" safety property every
  other feature in this package has. Values are computed live, every
  request; the endpoint is deliberately not part of `/schema`.
  Authorization is the widget's own `Widget::canView()`, called per request
  per user. A `canView()` denial, a throwing gate, a construction failure, a
  throwing `mount()`, or unreadable data all mean the widget is **absent**
  from `widgets` — indistinguishable on the wire, reasons surfacing only in
  `_warnings` (non-production) and `filament-mobile:doctor`. One broken
  widget never 500s the dashboard for every user. `mount()` is invoked (via
  reflection, inside the same degradation guard as everything else) because
  construction alone is not the full Livewire lifecycle a widget like
  `ChartWidget` can depend on. `Stat::getValue()` is stringified once,
  server-side — a value object's `__toString()` is honoured, and something
  genuinely unrenderable becomes `null` and warns rather than silently
  becoming `""`. Chart datasets are normalised to `label` + numeric `data`
  only, not Chart.js passthrough; a dataset without numeric data is dropped
  with a warning. `filament-mobile:doctor` reports a configured class that
  does not exist, is not a Filament widget, is neither a stats nor a chart
  widget (e.g. a `TableWidget` or a custom Blade widget), or whose
  construction/`mount()`/data-read fails — all non-zero exit; a `canView()`
  denial is deliberately not reported as a configuration problem.
  `contract/dashboard.json` is a golden fixture generated through the real
  endpoint. Known weakness: one endpoint means the response waits for the
  slowest configured widget.
- **Stats headings are published.** A `StatsOverviewWidget`'s `getHeading()`
  and `getDescription()` — which the web panel renders — now travel on the
  stats node as `heading`/`description` instead of a hardcoded `null`.
- **Numeric-string chart data is accepted.** MySQL PDO returns strings for
  `DECIMAL` aggregates (`SUM(total)` on a money column); those datasets now
  publish as floats instead of being dropped. An empty series (zero rows
  this period) publishes as `[]` — a normal state, no longer warned as a
  panel bug. Chart data is read through Filament's `getCachedData()` memo,
  so a chart widget's queries run once per request, not twice. An `Htmlable`
  stat label now warns when dropped, and a widget class whose autoload
  throws degrades (and is reported by doctor) instead of 500ing the
  dashboard. `doctor` runs the same dataset normalisation the endpoint
  does — a dataset that would be dropped is now a finding — and reports a
  non-string `widgets` config entry instead of crashing.
- **Schema caching.** `GET /schema` now sends a weak `ETag`
  (`W/"<sha1 of the built document>"`), hashed **before** `_warnings` is
  attached so the ETag does not move between `local` and `production` for
  an unchanged document. A matching `If-None-Match` — tolerant of a
  comma-separated list, the weak or strong form, and `*` — gets back a
  `304` with an **empty body**, still carrying the `ETag`. The hash is a
  content hash, not anything identity-derived, so it is automatically
  correct per user without this endpoint knowing who is asking. An
  unencodable document (invalid UTF-8 in a translated label) throws rather
  than hashing the empty string, which would collide every failing
  document onto the same ETag. **Additive: a client that sends no
  `If-None-Match` sees no change at all** — same `200`, same body, plus one
  new response header. **Known cost, stated plainly: a `304` saves
  bandwidth, not server CPU** — the document is still built in full to hash
  it; the deliberately-deferred win is a server-side document cache, out of
  scope this slice. See the README's Schema caching section and
  `contract/README.md`'s `ETag`/`If-None-Match` section. This is P6b — the
  second of P6's six sub-projects; the Dart client half (a host-supplied
  `FilamentConditionalTransport` + `FilamentSchemaCache`, cold-start-render-
  then-revalidate) is `filament_mobile`'s own 0.5.0.
- **Repeater.** A JSON-column `Repeater::make('items')->schema([...])` is a
  working, validated, editable field on the phone — previously `Repeater` was
  unmapped entirely, so the walker dropped it with a warning and emitted no
  node. It publishes a `repeater` node carrying its item template as
  `children`, published once and not per stored row — the same shape a
  layout container already uses, so the client's existing recursive walk
  needs no special-casing. `config` carries `addable`/`deletable` (off
  `isAddable()`/`isDeletable()`), `minItems`/`maxItems`, `itemLabel` (always
  `null` this slice — no row exists yet at schema-generation time), and
  `reorderable` — published for a host rendering its own repeater; this
  package's own client always treats it as `false` regardless. Per-item
  rules are published **and** enforced: a child's rules travel under
  `items.*.field`, so a `PUT` violating one 422s with a key shaped exactly
  `items.0.field`, and the repeater's own rule (`array`, `required`,
  `min`/`max` from `minItems`/`maxItems`) bounds the array itself. **The
  name-space split, the sharp part of this release:** `RuleExtractor` emits
  both `items` and `items.*.field`, but `WritableNames` — the mass-assignment
  whitelist and the settle's allow-set — takes `items` only. `Arr::set()`
  (`Write\SettledSchema::reset()`) has no wildcard support, so a starred name
  in the allow-set would write a literal `*` key rather than resetting every
  row, corrupting the state it exists to protect; `Arr::has()` has the same
  gap the other way, answering `false` for a starred path against a real
  array. `RuleExtractor::writableComponents()` is a second output of the
  same descent, not a second pass, so "has a rule" and "is writable" can
  never drift back into the old `array_keys(fromComponents())` identity a
  future simplification might otherwise reintroduce. `Repeater::relationship()`
  is refused, not supported: its node publishes `config.readOnly: true` and a
  submitted value for it is silently dropped on write, the same refusal a
  disabled field gets — solving relationship writes here would mean solving
  the relation-manager problem twice. **`config.readOnly` is published both
  ways round** — `false` for an ordinary JSON-column repeater, not merely
  omitted — because a client reads an absent key as read-only (absence means
  a server predating repeater support), so omitting it rendered every
  ordinary repeater inert in this package's own client; the Dart contract
  test now parses the committed `contract/laravel-panel.json` and asserts
  both directions. The relationship gate behind it fails **closed**, like an
  upload field's `accept`/`maxSize` gates: a throwing `getRelationship()` —
  which through `read()`'s fallback used to read as "not a relationship" and
  publish an editable control the write path can never save — publishes
  `readOnly: true` with a warning. `Repeater::setUp()`'s own
  `defaultItems(1)` default (which keys its one blank item under a freshly
  generated random UUID on every evaluation, verified empirically) is
  withheld from both `/schema`'s `default` and `FormDefaults`, so neither the
  ETag nor an ordinary create that never mentions the field is corrupted by
  it. `filament-mobile:doctor` reports, informationally, three shapes this
  slice does not support: a relationship repeater, one containing a `live()`
  field (the item template is static), and a nested repeater (rendered
  read-only by the client). **No break for a host implementing
  `ResourceDataSource` or the schema types directly** — this is a new
  component type parsed the same way every other node already is; nothing
  about the existing wire shape changed. This is P6c — the third of P6's six
  sub-projects; the Dart client half (`RepeaterComponent`,
  `RepeaterFieldWidget`, per-row validation and error mapping) is
  `filament_mobile`'s own 0.5.0. See the README's Repeater section and
  `contract/README.md`'s "The repeater field" section for the wire shape.
- **Repeater close-out** (whole-branch review of P6c). Four defects, all in
  the same family — a property no test read through the shared fixture, or a
  doc sentence no test read at all.
  - **A child that cannot round-trip no longer destroys stored data.** Inside
    a repeater, withholding a child's rule does not withhold the child: the
    whole array is one attribute, `validated()` rebuilds it from the paths
    its rules name, and the unruled key is **deleted from every row that gets
    written**. `Hidden::make('id')` in an item template — a common shape —
    lost its value on every save, behind a `200`, as did
    `->disabled(fn () => ! $user->can(…))` on a child, which is the exact
    inversion of what that guard exists to do. At top level the same
    mechanism protects the column. There is no row identity on the wire to
    merge the stored value back by (an index-merge pairs row 2's `id` with
    row 3's data the moment a row is added), so the field now fails closed:
    `RuleExtractor` gives it no rule and no writable name,
    `SchemaWalker` publishes `config.readOnly: true` off the same predicate
    (`RuleExtractor::withheldChild()` — one predicate, two readers, so flag
    and refusal cannot drift), the stored rows stay readable on `GET`, and
    `filament-mobile:doctor` names the offending child.
  - **A relation-write child forced back into the row no longer destroys
    stored data.** The same defect as the bullet above, reached the one way
    that bullet's predicate did not cover: `RuleExtractor::withheldChild()`
    mirrors `childrenOf()`'s refusals, but the rules come from
    `fromComponents()`, which applies one filter more — it drops every
    relation-write leaf, because such a value reaches the database through
    `saveRelationships()` rather than the payload. So a
    `CheckboxList::relationship()` in an item template was minted by the
    descent, dropped from the rules, and published editable. Normally
    harmless (`relationship()` sets `dehydrated(false)` as a literal, so
    Filament never puts the key in the row's state), but
    `->dehydrated(true)` overrides that literal: the key is stored, no rule
    names it, and `[{"title": "A", "tags": [1, 2]}]` saved as
    `[{"title": "A"}]` behind a `200`. The predicate now mirrors
    `fromComponents()`'s filter too, exempting a relation-write child only
    when its dehydration **answers** `false` — a gate that cannot answer
    refuses, as everywhere else in this package. **`withheldChild()` must
    now be kept in step with two callers**; see `HANDOFF.md`.
  - **A nested repeater is published `readOnly: true`.** The spec, both
    READMEs and the client widget's own docblock all said the walker already
    did this; nothing ever implemented it, so a nested repeater shipped
    editable and its `422` came back keyed `outer.0.inner.1.x`, which no
    client field can render — an unsubmittable form with no explanation. Its
    rows still round-trip as part of the outer array.
  - **A repeater's value must be a `list`, not merely an `array`.** PHP's
    `array` admits a string-keyed map and the per-item wildcard rules match a
    literal `*` key, so `{"line_items": {"*": {…}}}` validated cleanly and
    stored verbatim behind a `200` — a shape neither this contract nor
    Filament's own web `Repeater` reads back. Now a `422`; `[]` still passes.
  - **`Repeater::relationship()->dehydrated(true)` no longer 500s.** The
    literal `dehydrated(false)` that `relationship()` sets was the only thing
    keeping a relationship repeater out of the write path — override it and
    the node said `readOnly: true` while `WritableNames` said writable, and a
    crafted payload reached `update()` as a column that does not exist
    (`QueryException`). The repeater branch now reads the same relationship
    gate the walker publishes (`FieldPersistence::refusesRelationship()`).
    Fixed narrowly, at the repeater: reclassifying
    `savesViaRelationship()` itself would flip `neverPersists()` for every
    relationship repeater and route its rows into the controller's relation
    pass — the deliberate `Repeater`-aware branch stays deferred.
  - `MobilePanelController::leafNames()` now identifies a repeater with no
    column of its own as `config.readOnly: true` **and** `writable: false`
    together, rather than `readOnly` alone: `readOnly` stopped meaning "no
    column" the moment it started being published for nested and
    non-round-tripping repeaters, both of which have real columns whose rows
    must stay readable.
  - **Docs corrected, not just code.** `README.md` claimed "the server
    enforces exactly what the config publishes" for
    `addable`/`deletable`/`minItems`/`maxItems`; only `minItems`/`maxItems`
    become rules. **Decision: `addable`/`deletable` stay client hints and are
    not enforced server-side** — a row-count bound is a statement about the
    stored value, `addable(false)` is one about the editing gesture, and
    enforcing it would need a stored-versus-submitted comparison the write
    path does for no other field. Use `minItems(n)->maxItems(n)` for an
    enforced count. Said plainly in both READMEs, `contract/README.md` and
    the spec's Known weaknesses.

- **Relations.** A resource's `getRelations()` — the same relation
  managers a Filament panel already declares — becomes read-only,
  paginated child lists on mobile: `GET
  /{resource}/{record}/relations/{relation}`, the same `{data, meta}`
  envelope and `RecordSerializer` `index()` already uses. `/schema` gains
  a `relations` array per resource, always present (`[]` for a resource
  with none), each entry carrying `key` (the relationship name, what the
  endpoint is addressed by), `label` (the manager's own title, falling
  back to a humanised key), `card` (derived from the manager's own
  `table()` columns — first becomes title, second subtitle — overridable
  with `MobileResource::relationCard()`), and `recordKey` (the **related**
  model's own route key, not the parent's). Column introspection goes
  through `HeadlessTableHost`, which gains a second method beside
  `flatActionsFor()` — still one of the architecture test's three fixed
  exempt files; no new file in `src/` imports `Filament\Tables\Table`.
  Three gates, all failing closed: the parent's own `show()` authorization
  (both its class-level `viewAny` and the record's own `view`),
  `RelationManager::canViewForRecord($ownerRecord, $manager)` (passed the
  manager's own class-string — Filament's own no-page fallback), and the
  child model's `viewAny`. **Gate 2 runs under guard impersonation**: it
  resolves `Filament::auth()->user()` — the panel's guard, not the
  request's — so left ambient it answers about whoever holds a panel
  session, measured both ways (a 403 for a user their own policy allows,
  a 200 for a stranger riding an admin's session) before the fix. The
  request's user is impersonated onto the panel's guard for the duration
  of the gate call and restored in a `finally`, in-memory only; a null
  request user refuses outright rather than being impersonated, since
  impersonating null only clears the guard's cache and a session guard
  would then answer for its cookie holder. **A relation whose table adds
  query scopes (`->modifyQueryUsing(...)`) is refused entirely** — absent
  from `/schema`, its endpoint 404s (never 403 — a 403 would suggest it
  might appear for someone else), `filament-mobile:doctor` names it.
  Measured, not assumed: outside Livewire, `Table::getQuery()` returns a
  Builder whose model is `NULL` and whose SQL is `select * where "status"
  = ?` — the query scope survived, the relation binding to the owner
  record did not, under two different table hosts. The narrowing is
  detectable (`Table::$queryScopes` by reflection) but not reproducible,
  so the relation is refused wholesale rather than served unscoped. That
  reflection would be this design's one fail-open risk across `^4.0|^5.0`
  if left bare, so it is not: a `property_exists()` tripwire refuses every
  relation loudly if the property is ever renamed, and a test asserts the
  property exists on both majors rather than only the behaviour it drives.
  Verified by renaming it in `vendor/` — 26 tests red, zero relations
  published. Known weaknesses,
  stated in the README: a relation manager that narrows its query is
  invisible on mobile — for some panels that may be most of them; the
  manager's filters, search and sorting are ignored; only the first two
  columns become a card; nothing is writable. This is P6d — the fourth of
  P6's six sub-projects; the Dart client half (`RelationDescriptor`, the
  record screen's relation section, the full paginated list, host-owned
  "See all" navigation) is `filament_mobile`'s own 0.5.0.
- **Relations hardening** (P6d whole-branch review). Four fixes, each
  reproduced before it was written:
  - **The relation endpoint no longer N+1s.** It is documented as
    mirroring `index()`, and `index()` has carried
    `->with($card->relationPaths())` since P1 — the relation path never
    did. A card declaring `subtitle('company.name')` served 10 rows in
    **14 queries** against `index()`'s 4, one extra per row and unbounded
    by `per_page`. The eager load is carried over, and a test now counts
    the queries so the two paths cannot silently diverge again.
  - **A host-declared card that fills no slot is no longer a relation.**
    The rule was always "no card ⇒ no relation", but both enforcement
    sites tested `$card === null`, and `relationCard('key', fn ($c) => $c)`
    builds a card that is empty and *not* null: `/schema` shipped
    `"card": []`, the endpoint 200'd with rows carrying nothing but their
    id, the Dart client silently dropped the relation, and `doctor` said
    nothing. The test is now `fieldPaths() === []`.
  - **A relationship that does not resolve on the model is refused.**
    `protected static string $relationship = 'ghosts'` was published by
    `/schema` and then 403'd by gate 2 — a control that cannot work, and
    a panel bug filed as a permission problem. `RelationDiscovery` now
    builds the relationship on an unsaved instance (building never
    queries) and refuses when it does not resolve.
  - **`relationCard()` validates.** A key matching no relation is named by
    `doctor` instead of silently falling back to the derived card, and a
    closure returning something other than a `MobileCard` is refused at
    declaration time, the way `defaultSort()` already refuses its own.

  All card resolution moved into `RelationDiscovery`, which is the
  structural half of the fix: `/schema` and `RelationController` each used
  to resolve the card and separately decide whether it was usable, so the
  same rule was written twice and both copies had the same hole. It is now
  decided once, and — this is the point — every refusal reaches
  `refusalsFor()`, so `doctor` names it. The two refusals the README and
  the design spec already promised but nothing implemented (a cardless
  relation, and one whose relationship does not resolve) are now real
  rather than corrected away in the docs.
- **Rich text (read only).** A `RichEditor`-backed infolist entry stops
  publishing raw markup and refines to a new `rich_entry` type — joining
  `ComponentTypeMap::REFINED`, exactly as `badge_entry` does — when the
  entry called `->prose()` or the resource's model implements
  `HasRichContent` for that column. **No break for any host**: `data.<path>`
  is **unchanged**, still the raw string the form's `textarea` prefill
  reads, so the write path is untouched and no existing form test moves;
  the two derived shapes ride a flat sibling key instead, `<path>.__rich =
  {doc, text}`, absent entirely when there is nothing to convert, and a
  client that never learned about `rich_entry` or the sibling keeps working
  exactly as it did before this shipped. `RecordSerializer` produces the sibling
  once, so `index()` and `show()` cannot disagree about it. Conversion runs
  through Filament's own `RichContentRenderer` — a **default-configured**
  `RichContentRenderer::make()`, not the panel component's
  `RichEditor::getTipTapEditor()` — never a bare `Tiptap\Editor`, which was
  measured to silently drop link marks the renderer keeps;
  `ueberdosis/tiptap-php` reaches this
  package transitively through `filament/forms`, so nothing was added to
  `composer.json`. A closed vocabulary of ten node types and six marks
  (see the README's Rich text section) is enough for `RichText`/`Column` on
  the client, no new Flutter dependency. Sanitisation against `<script>`
  and friends is a **consequence** of running the column through TipTap's
  own extension whitelist, not a feature that was built — which is exactly
  why it has its own test, asserted on the **whole** `__rich` envelope:
  `text` is flattened from the converted document (flattening the raw
  string with `strip_tags` kept a `<script>` body verbatim), and an
  already-JSON column is re-serialised to HTML and reparsed so the one
  whitelist governs every path in. **Cards get the sibling only for a
  model-declared column** — a `->prose()`-only entry governs that one
  infolist entry, not the list row, and `index()` builds no infolist to
  resolve it on every list request; `filament-mobile:doctor` names this
  combination under "Rich text on cards" with the one-line model fix.
  Known weaknesses, stated in the README: no editing (the form field stays
  a `textarea` over the raw string); no tables, no custom TipTap blocks;
  panel-registered plugins and `->linkProtocols()` are **not** inherited,
  because they are configured on the `RichEditor` form component and the
  read path never builds one; `textAlign` is published and not honoured,
  pending the RTL/i18n slice;
  and the conversion was uncached per request at this
  release — memoised per request in 0.6.0; the `RelationDiscovery::for()`
  half of that envisioned pass was later measured and deliberately not done,
  the split already running exactly once per resource per request at every
  HTTP entry point. This is P6e — the fifth
  of P6's six sub-projects; the Dart client half (`RichDocument`,
  `EntryKind.rich`, `RichEntryTile`, host-wired link tapping) is
  `filament_mobile`'s own 0.5.0.
- **Locale and direction.** `/schema`'s `panel` block gains `locale`
  (`app()->getLocale()`) and `direction`, a closed `ltr`/`rtl` read from
  Filament's own `filament-panels::layout.direction` — the same key the web
  panel lays itself out with, so the phone and the panel agree by
  construction rather than by this package maintaining a locale table.
  **Read the namespace carefully**: `filament::layout.direction` does
  **not** resolve and returns the raw key, silently leaving every panel
  stuck on `ltr` with no error — one character class away from the correct
  `filament-panels::` namespace. An unrecognised locale falls back to `ltr`
  through Filament's own translation chain; a panel that overrode the key
  with a nonsense value normalises to `ltr`; a throwing translator degrades
  to `ltr` rather than 500ing `/schema` — the same per-property
  degradation the rest of the document already applies. `GET /dashboard`
  publishes the same `direction` beside `widgets` (it carries no `panel`
  block of its own to read one off), through
  `PanelSchemaBuilder::direction()`, now `public static` so both endpoints
  share one body rather than one rule written twice. **No break for any
  host**: both are additive keys, and `/schema`'s `ETag` needed no code
  change — it is already a content hash of the built document, so a
  locale's labels changing already moved it, and `locale`/`direction` are
  simply two more fields inside that same hash. This is P6f — the sixth
  and last of P6's six sub-projects, closing P6. The Dart client half
  (`Directionality` wrapping every screen unconditionally, the four
  direction-unsafe widgets — two horizontal `left:` paddings, an
  `Alignment.centerRight` and one composite padding — plus the rich-text
  blockquote's `left` border, which had to move with its indent;
  `textAlign` honoured; bidi-isolated grouped digit runs) is `filament_mobile`'s own 0.5.0.

## 0.2.0 — 2026-08-06

- **Relation writes.** `Select::multiple()->relationship()` and
  `CheckboxList->relationship()` pivots sync through Filament's own
  `saveRelationships()`, isolated per component so one throwing closure
  cannot take the rest of the form down. A key absent from the payload
  leaves the pivot untouched; an explicit `[]` clears it — absence is not
  emptiness, so a partial `PUT` never wipes a relation it did not mention.
  A disabled relation field refuses both ways. Singular relationship
  containers (`Section::make()->relationship()`) are still not saved.
- **Actions.** `MobileResource::actions(['approve', 'archive'])` opts named
  record actions the resource's own `table()` already defines into the
  mobile API — de-duplicated, declaration order preserved. `GET
  /{resource}/{record}` gains a per-record `actions` array beside
  `permissions` (always present, `[]` when none opted in, absence means
  unavailable). `POST /{resource}/{record}/actions/{action}` runs one
  through Filament's own authorization and closure — 200 on success, 422
  with the failure title when the action halts OR reports failure without
  halting (`$action->failure()`, matching the web panel's `getStatus()`
  switch), 200 with no message on `Cancel` (the web panel's silent no-op),
  500 on a genuine throw, gate order matching every other endpoint.
  `PUT` and `DELETE` now apply the same `viewAny` gate before the record
  lookup that `GET /{record}` and the action endpoint already did, so a
  caller denied at the resource level gets an identical 403 for a real and
  a fake id on every endpoint.
  `filament-mobile:doctor` reports an action name that resolves to nothing,
  one that carries a form, or a `table()` it cannot build headlessly — all
  non-zero exit. Not supported this slice: actions with modal forms, bulk
  actions, page-level actions, action groups, list-card actions.

## 0.1.0 — 2026-08-06

Initial release.

- `GET /schema` publishes every opted-in resource the authenticated user may
  see: labels, navigation group, permissions, card, sorts, form and infolist.
- Read path: paginated card lists and single records widened to the form's
  and infolist's fields, with per-record permissions.
- Write path: create, update and delete authorised through the panel's own
  policies; validation rules are extracted from the same schema the read path
  publishes, so shown rules and enforced rules cannot drift.
- `POST /{resource}/state` re-evaluates the schema against submitted values
  for reactive forms; dependent select options are resolved server-side and
  crafted sibling state cannot widen them.
- Closure evaluation is isolated per component (`SafeEvaluator`): one
  throwing closure degrades its own node instead of killing the response.
- Tested against Filament 4.12 and 5.7, SQLite and MySQL.

Not yet supported: relation writes (e.g. `Select::relationship()` pivot
sync), Filament actions, dashboard widgets.
