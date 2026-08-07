![gait/filament-mobile](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/banner.png)

# gait/filament-mobile

Serves an existing Laravel Filament panel as a mobile admin JSON contract. It
reads your resources' existing `form()` and `infolist()` — you do not redeclare
them — and exposes only the resources you explicitly opt in.

| Endpoint | Returns |
|---|---|
| `GET /api/mobile-panel/schema` | Every opted-in resource the user may see: labels, permissions, card, sorts, form and infolist |
| `GET /api/mobile-panel/{resource}` | A paginated list of card payloads |
| `GET /api/mobile-panel/{resource}/{record}` | One record, widened to the form's and infolist's fields, plus per-record permissions |
| `GET /api/mobile-panel/{resource}/{record}/relations/{relation}` | One relation manager's child rows, read-only, same envelope as the list |
| `POST /api/mobile-panel/{resource}` | Create, validated from the resource's own schema |
| `PUT /api/mobile-panel/{resource}/{record}` | Update, same validation |
| `DELETE /api/mobile-panel/{resource}/{record}` | Delete, gated on the record's own policy |
| `POST /api/mobile-panel/{resource}/state` | Re-evaluate the schema against submitted values, for reactive forms |
| `GET /api/mobile-panel/dashboard` | The panel's opted-in dashboard widgets, values computed live |

Every write is authorised through the panel's own policies, and its validation
rules are extracted from the same schema the read path publishes — so the rules
a client is shown and the rules the server enforces cannot drift apart.

![How it works](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/diagram.png)

---

## Install

```bash
composer require gait/filament-mobile
php artisan vendor:publish --tag=filament-mobile-config
```

Then apply the two items below, and opt at least one resource in.

## Read this first: two things that will bite you

Both produce a **working-looking HTTP 200 with an empty or wrong payload**, not
an error. Neither is obvious from a stack trace, because there isn't one.

### 1. The routes are not in a middleware group, so session auth cannot work

The package registers its routes with `loadRoutesFrom()` and applies only
`auth` (or `auth:{guard}`). They are **not** in your `web` or `api` group, so
no session middleware runs. Laravel's default guard is a *session* guard, so
out of the box `$request->user()` is always null and every request is a 401.

**Use a token guard.** For Sanctum:

```php
// config/auth.php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'sanctum' => ['driver' => 'sanctum', 'provider' => 'users'], // add this
],
```

```php
// config/filament-mobile.php
'guard' => 'sanctum',
```

### 2. If you use filament-shield or spatie/laravel-permission, pin `$guard_name`

This is the one that costs a day. Laravel's `auth:sanctum` middleware calls
`Auth::shouldUse('sanctum')`, which **rewrites `auth.defaults.guard` for the
rest of the request**. Spatie's `Guard::getDefaultName()` then resolves your
user's guard to `sanctum` and looks for `view_any_*` permissions under *that*
guard. Every permission row your admin panel created is `guard_name = 'web'`,
so nothing matches, every policy denies, and:

```jsonc
// GET /api/mobile-panel/schema, as a super admin
{ "version": 1, "panel": {...}, "resources": [] }
```

That response is **byte-identical to what a genuinely unauthorized user gets**.
There is no error, no warning, and no way to tell the correct-secure answer
from the completely-broken one by looking at it.

Fix it on the model:

```php
class User extends Authenticatable
{
    use HasRoles;

    // Roles and permissions are stored with guard_name = 'web'. Pin the
    // lookup so a token-authenticated API resolves the same permissions the
    // session-authenticated web panel does.
    protected $guard_name = 'web';
}
```

> The root cause is in Laravel and Spatie, not this package — but the package's
> only viable configuration walks you straight into it, so it is documented
> here rather than left to be discovered.

**If `/schema` returns `"resources": []` for a user you know is an admin**, this
is almost certainly why. Confirm with `php artisan filament-mobile:doctor`.

---

## Opt a resource in

A Filament resource is invisible to the mobile API until it declares a static
`mobile()`. That is the safety property: pointing this package at a 200-resource
admin exposes nothing until you say so, one resource at a time.

```php
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;

class CompanyResource extends Resource
{
    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card): MobileCard => $card
                ->title('company_name')
                ->subtitle('user.name')          // dotted: eager-loaded automatically
                ->badge('status', ['pending' => 'warning', 'approved' => 'success'])
                ->meta('city')
                ->meta('created_at'))
            ->searchable(['company_name', 'city'])
            ->sorts([
                'company_name' => __('Name'),
                'created_at' => __('Created'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

You do **not** declare the form or the infolist. They are read from the
resource's existing `form()` and `infolist()` methods.

### `MobileCard`

| Method | Notes |
|---|---|
| `title(string $field)` | |
| `subtitle(string $field)` | |
| `leadingImage(string $field, ?string $fallback = null)` | Must be a real attribute or accessor — see the limitation below |
| `badge(string $field, array $colors = [])` | `$colors` maps a value to a semantic colour name |
| `meta(string $field, ?string $format = null)` | Repeatable |

**Every dotted path is eager-loaded automatically.** `subtitle('user.name')`
adds `->with('user')` to the list query and nests as `{"user":{"name":…}}`.
This is the entire N+1 defence and it needs no declaration.

**The card's fields are also the serialisation whitelist.** A column no
declared screen references never reaches the phone, so adding a secret column
to a table cannot leak it. The detail endpoint additionally serialises the
fields the infolist names.

### `MobileResource`

| Method | Notes |
|---|---|
| `card(callable $configure)` | |
| `searchable(array $columns)` | Plain columns only — a dotted path would need a join and is not supported |
| `sorts(array $labels)` | `['column' => 'Label']`; the key is spent on the database |
| `defaultSort(string $key, string $direction = 'asc')` | Must be one of the declared `sorts()` keys |

## What ships

| Feature | What it gives you |
|---|---|
| [Resources and cards](#opt-a-resource-in) | Opt in per resource; the card is declared, the form and infolist are read from the resource |
| [Actions](#actions) | The panel's own record actions, published per record with their authorization already applied |
| [Upload](#upload) | Single-file `FileUpload` / `SpatieMediaLibraryFileUpload`, with the field's own accept and size rules enforced |
| [Repeater](#repeater) | JSON-column repeaters, validated per row |
| [Relations](#relations) | Relation managers as read-only child lists |
| [Rich text](#rich-text) | `RichEditor` columns as a structured document, sanitised by construction |
| [Dashboard](#dashboard) | The panel's opted-in widgets, computed live |
| [Locale and direction](#locale-and-direction) | The panel's own locale and `ltr`/`rtl`, so the phone lays out the way the panel does |
| [Schema caching](#schema-caching) | An ETag on `/schema` so a cold start is one conditional request |

Everything below is reference. Read the section for the feature you are wiring;
each ends with a **Known weaknesses** list stating plainly what it does not do.

## Actions

A resource opts specific **table** record actions into the mobile API by
name. The package never builds an action of its own — declaring
`->actions([...])` says WHICH of the resource's own `table()` actions travel
to a phone; the real `Filament\Actions\Action` object still decides label,
color, icon, confirmation, authorization, and what the closure does.

```php
MobileResource::make()
    ->card(...)
    ->actions(['approve', 'archive'])
```

Names are de-duplicated and travel in declaration order. Each must resolve
to a record action the resource's `table()` already defines (record actions
nested in a group are found too — the search is flat). Two things never
reach a phone, both reported by `filament-mobile:doctor` with a non-zero
exit rather than failing silently:

- a name that resolves to nothing — a typo, or an action that lives only on
  the resource's page rather than its table;
- a name that resolves to an action carrying a form — modal-form actions are
  not supported this slice, so the action is omitted from the wire rather
  than shipped half-working.

A `table()` doctor cannot even build headlessly (outside Livewire) is
reported the same way — actionable, non-zero. None of this breaks `/schema`
or the record endpoints; a misconfigured action is simply absent, the same
degrade every other closure in this package gets.

### On the record payload

`GET /{resource}/{record}` gains `actions`, a sibling of `permissions`:
evaluated per record, so an action hidden or unauthorized for THIS row is
not in the list — no disabled button, the same rule `permissions` already
follows. Always present, `[]` when the resource opted none in. See
`contract/README.md` for the exact node shape.

A throwing label, color, or icon closure degrades only that field — label
falls back to the action's own machine name, color/icon to `null` — and the
action stays in the list and stays runnable: a cosmetic failure must not
cost a capability. A throwing visibility or authorization closure omits the
action entirely, same as every other gate in this package. A throwing
confirmation closure is the one exception that fails **closed**: the action
still carries a non-null `confirmation`, with a generic heading and empty
`submit`/`cancel`, so nothing can be tricked into running promptless — see
`contract/README.md` for what a client must do with an empty
`submit`/`cancel`.

### Running one

```
POST /api/mobile-panel/{resource}/{record}/actions/{action}
```

Resolution order matches every other endpoint: resource 404 → `viewAny` 403
→ record 404 → the action's own gate, 403. Every refusal — unopted,
unresolved, form-carrying, hidden, unauthorized — is the same bodyless 403,
so a probing client cannot tell which reason it hit. The published `actions`
array on the record is a **hint**; this endpoint re-answers the gate against
the record as it stands at call time, never trusting what it last
published.

| Status | Meaning |
|---|---|
| `200 {"message": string\|null}` | The action ran. `message` is its own success notification title, when it declared one. |
| `422 {"message": string\|null}` | The action halted (`$action->halt()` / Filament's failure-notification path). `message` is its failure title. |
| `500` | The action's own closure threw. Never reported as a success — an action that half-ran must not tell the client it finished. |

The response carries no record body. The client re-fetches: an action's
most common effect is exactly what `permissions` and `actions` report, so
the re-fetch refreshes both.

### Not supported this slice

Actions with modal forms, bulk actions, page-level (header) actions, action
groups, and list-card actions. Row actions declared on the resource's
`table()` are the whole surface.

## Upload

A single-file `FileUpload` (and `SpatieMediaLibraryFileUpload`) field is
editable from a phone. `FileUpload::multiple()` is not — it still publishes
`config.readOnly: true` on `/schema` and this endpoint always refuses it,
reported informationally by `filament-mobile:doctor` (not a CI failure: a
panel legitimately has multi-file fields, this slice just doesn't support
them yet).

```
POST /api/mobile-panel/{resource}/upload
```

Multipart: `file` (the bytes) and `field` (the form field's name — the
statePath, so a nested field is addressable). No record is involved — an
upload happens while a form is open, on create as well as edit. Success is

```jsonc
200 { "path": "<stored path>" }
```

**Gate order matches every other endpoint:** resource 404 → `viewAny` 403 →
field-resolution 403. `Upload\UploadFieldResolver` re-derives the field
through the same `SettledSchema`/`WritableNames` machinery the write path
uses — the published schema is a hint, never the gate — and it returns
`null`, never throws, for every reason a write would be refused: an
unresolvable name, a disabled field, a disabled ancestor container, a name
that resolves to more than one component, or a component that isn't an
upload at all. **Every refusal comes back as the same bodyless 403**, so a
client cannot map a panel's field names by probing which ones 403 differently.
Its final check is the write path's own `WritableNames` allow-set, so a field
this endpoint accepts and a field `store()`/`update()` will actually persist
cannot drift apart.

**Constraints are enforced server-side, never trusted from the client.**
`getAcceptedFileTypes()` and `getMaxSize()` are read off the resolved
component and applied as real Laravel validation rules — a violation is a
`422` in the same shape the write path's validation errors use, so a client
renders it with machinery it already has. The type check uses Laravel's
`mimetypes:` rule, which **sniffs the file's actual content**, never
`mimes:`, which trusts the client-supplied extension — a `.png` that is
really a shell script fails the `mimetypes:` check regardless of what its
`Content-Type` header or filename claim. A field whose
`acceptedFileTypes()`/`maxSize()` closure throws fails closed with a `422`
rather than accepting anything.

**The stored filename is never the client's filename, and its extension
comes from the sniffed MIME clamped to a fixed allow-list.** The extension
is looked up from the sniffed MIME type and then checked against a
package-controlled `SAFE_EXTENSIONS` constant (`png`, `jpg`, `jpeg`, `gif`,
`webp`, `pdf`); anything outside that list stores with **no extension at
all**, never the mapped one. This is deliberate, not incidental: Symfony's
full MIME-to-extension table maps types a misconfigured webserver can be
made to execute (`application/x-httpd-php` → `php`) straight through, and
whether a given upload's sniffed MIME ever lands on one of those entries
depends on the deployment's libmagic build. Clamping to an explicit,
package-controlled list means "can this endpoint ever write an executable
extension" is answerable by reading one constant, not by auditing every
libmagic quirk a host might run — it's what makes an uploaded polyglot inert
on disk regardless of what a client claimed it was. If a real panel needs
more (`.zip`, say), **widen `SAFE_EXTENSIONS` deliberately, by name — never
by falling through to Symfony's table**: the moment the lookup trusts the
full table, that safety question becomes a question about each deployment's
libmagic build again.

**Storage bypasses Filament's `saveUploadedFile()`.** That method's
signature requires a Livewire `TemporaryUploadedFile`, which a plain Laravel
`UploadedFile` from this endpoint does not satisfy — importing that
lifecycle is exactly the coupling this package exists to avoid. Instead the
file is stored directly through the resolved component's own
`getDiskName()`/`getDirectory()`/`getVisibility()`, landing at the same path
Filament's own web panel would produce.

**The returned path becomes the field's value, and the ordinary write path
saves it as a plain string — no change to `store()`/`update()`.** This
mirrors Filament's own web panel, which also stores on pick, not on save.
`RuleExtractor` now admits a single-file field's rule (multiple stays
withheld), so the stored path enters the validated payload exactly like any
other column value. The flip side: a single-file column accepts **any**
string through the ordinary write path — matching the web panel's own
Livewire-tamperable property — so a host must not feed that column to
`Storage::download()`/`Storage::url()` (or any path-sensitive sink)
unchecked.

**Orphaned files accumulate.** A user who picks a file and abandons the form
leaves a stored file with no row pointing at it — the same property
Filament's own temporary-upload directory has. This package does not clean
them up; a host that wants that prunes the storage directory on its own
schedule (e.g. delete anything older than N days with no matching row). No
claim-on-save handshake exists to avoid this — that would be a second
subsystem beside the one being built.

## Repeater

`Repeater::make('items')->schema([...])` over a JSON/array-cast column is a
working, validated, editable field on the phone. Before this it was entirely
unmapped: the walker dropped it with a warning and emitted no node, so the
field was invisible on mobile and any data in it was unreachable.

```php
Repeater::make('line_items')
    ->schema([
        TextInput::make('sku')->required()->maxLength(20),
        TextInput::make('qty')->numeric(),
    ])
    ->minItems(1)
    ->maxItems(5);
```

publishes a `repeater` node carrying the item template as `children` — the
same shape layout components already use, so the client's existing recursive
walk renders it unchanged:

```jsonc
{
  "type": "repeater",
  "name": "line_items",
  "children": [ /* the item template's own nodes, each with its own rules */ ],
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

`children` is the **template, published once** — not once per stored row.
`addable`/`deletable`/`minItems`/`maxItems` come straight off the field's own
`isAddable()`/`isDeletable()`/`getMinItems()`/`getMaxItems()`. **Only
`minItems`/`maxItems` become server rules.** `addable`/`deletable` are
**client affordances** — they tell a renderer not to draw an Add or a Remove
control, and nothing on the server refuses a crafted payload that adds or
removes rows anyway. That is deliberate, not an oversight: a row-count bound
is a statement about the stored value, which the server must own, while
`addable(false)` is a statement about the *editing gesture*, which only a
renderer can make. Enforcing it server-side would need a stored-versus-
submitted row-count comparison the write path does not do for any other
field. If you need the count fixed, say so with
`minItems(n)->maxItems(n)` — that is enforced. `reorderable` is published for
a host rendering its own repeater; **this package's own Flutter client always
treats it as `false`** regardless of what is published.

**`readOnly` is always published, both ways round** — `false` here, `true`
for a refused repeater. A client reads an *absent* `readOnly` as read-only,
because absence means a server predating repeater support and a client must
never invent a capability the server did not declare; that rule only works
while this server states the ordinary case explicitly.

**Three things earn `readOnly: true`**, and the write path refuses on the
same three predicates, so the published flag and the server's answer cannot
disagree:

1. **A relationship repeater** (`->relationship()`). The gate fails
   **closed**: `getRelationship()` throwing — or a component with no such
   accessor at all — publishes `readOnly: true` with a warning, never an
   editable control the write path would silently drop. Same shape as an
   upload field's `accept`/`maxSize` gates.
2. **A nested repeater** — a repeater inside another repeater's item
   template. Two levels of row coordinate is a different problem, and a
   nested row's `422` comes back keyed `outer.0.inner.1.x`, which the client
   has no field to render it against.
3. **A repeater whose item template holds a child that would not
   round-trip** — a `Hidden`, an unmapped component type, a `disabled()` or
   never-dehydrated field, a multiple-`file` field, or a **relation-write
   child whose `->dehydrated(true)` puts it back into the row's stored
   state**. See below; this is the one that would otherwise lose data.

**A child that cannot round-trip refuses the whole repeater.** At top level,
withholding a field's rule *protects* its column: the key never enters the
validated payload, so `update()` never touches it. Inside a repeater the
whole array is one attribute, and Laravel's `validated()` rebuilds it from
the expanded paths its rules name — so a row key with no rule is **deleted
from every row that gets written**. Same mechanism, opposite outcome:
`Hidden::make('id')` in a row template, or
`TextInput::make('rate')->disabled(fn () => ! $user->can('rates.manage'))`,
had its stored value destroyed on every save, behind a `200`.

A **relation-write child** reaches the same outcome by a different route and
is the shape to watch for, because it is the one whose rule is withheld
somewhere other than the descent. `CheckboxList::relationship()` saves through
`saveRelationships()`, so no rule ever names it — normally harmless, since
`relationship()` sets `dehydrated(false)` as a literal and Filament never puts
the key in the row's state at all. `->dehydrated(true)` overrides that literal:
the key IS stored and still has no rule, so
`[{"title": "A", "tags": [1, 2]}]` saved as `[{"title": "A"}]`. A child whose
dehydration gate cannot be evaluated is refused the same way — a gate that
cannot answer never admits.

There is no row identity on the wire — no keys, no reorder — so the only
merge available is by index, and an index-merge pairs row 2's `id` with row
3's data the moment a row is added or removed. Corrupting an identifier is
worse than refusing the control, so the field **fails closed**: no rule, no
writable name, `config.readOnly: true`, and the stored rows stay readable on
`GET`. `filament-mobile:doctor` names the offending child, which is the only
place a panel author can learn *which* one cost them the control.

**Only a JSON-column repeater is supported.** `Repeater::relationship()`
writes child rows through Filament's own `saveRelationships()`, which this
package's write path never calls — solving it here would mean solving the
relation-manager problem twice, or badly. A relationship repeater's `/schema`
node still appears, but with `config.readOnly: true`, and a submitted value
for it is silently dropped on write, the same refusal a disabled field gets.
`filament-mobile:doctor` reports it informationally, alongside three other
shapes this slice legitimately does not support: a repeater containing a
`live()` field (the item template is static — see below), a nested repeater
(published `readOnly: true`; two levels of row coordinate is a different
problem), and a repeater with a child that would not round-trip (named child
and all).

**Per-item rules are published and enforced**, not merely published. A child
component's own rules travel under `items.*.field` — `line_items.*.sku`,
`line_items.*.qty` — so a `PUT` that violates one comes back `422` with a key
shaped exactly `line_items.0.sku`, the row index Laravel's own validator
fills in. The repeater's own rule bounds the array itself: `array`, `required`
when the field is, `list`, and `min`/`max` derived from
`minItems`/`maxItems` — so a submission with too few or too many rows is
refused server-side, not merely discouraged by the client's
`addable`/`deletable` gates.

**`list`, not merely `array`.** PHP's `array` admits a string-keyed map, and
the per-item wildcard rules match a literal `*` key perfectly happily, so
`{"line_items": {"*": {"sku": "A"}}}` used to validate cleanly and store
verbatim behind a `200` — a shape neither the contract nor Filament's own web
`Repeater` can read back, which the mobile client then rendered as zero rows
and overwrote on the first Add. `list` turns that silent corruption into a
`422`. An empty repeater is unaffected: `[]` is a list.

### The name-space split — read this before touching `RuleExtractor`

`RuleExtractor` used to double as two things at once: it produced the
validation rules, and `WritableNames::of()` was literally
`array_keys(RuleExtractor::fromComponents(...))` — the mass-assignment
whitelist and the settle's allow-set were the same set of names. For a flat
form that identity is a feature. **A repeater breaks it**, and getting this
wrong corrupts state:

- Validation wants per-item paths — `items.*.name` — which is what Laravel
  natively understands and what a client needs to pre-validate a row.
- The settle (`Write\SettledSchema::reset()`) must never see them. It calls
  `Arr::set($state, $path, …)`, and `Arr::set` has **no wildcard support** —
  given `items.*.name` it creates a literal `*` key rather than touching every
  row, corrupting the very state it exists to protect. `Arr::has()` has the
  same gap in the other direction: it answers `false` for a starred path
  against a real array, so a naive "trust every rule name" reset would never
  copy a submitted row at all.

So the two are deliberately separate outputs of the same descent:
`RuleExtractor::fromComponents()` emits `items` **and** `items.*.field`;
`RuleExtractor::writableComponents()` — what `WritableNames::of()` actually
reads — emits `items` **only**. The repeater is one writable name whose whole
array is trusted or reset as a unit; the starred per-item names exist for
validation alone and must never reach the settle. **If a future change makes
`WritableNames::of()` read `array_keys(RuleExtractor::fromComponents(...))`
again — restoring the old identity because it looks like a simplification —
it silently reintroduces this corruption**, because `items.*.field` would
re-enter the allow-set as a literal, wildcard-shaped key `Arr::has`/`Arr::set`
cannot resolve against real submitted data.

**The settle treats `items` as one name.** A crafted row cannot open a gate a
trusted row would not, because the whole array is reset together whenever
`items` itself is not writable — a disabled repeater, or one inside a disabled
container, refuses exactly as any other field does: its name is withheld, so
no row reaches the database.

### Known weaknesses, stated now

- **No reordering.** Published as `config.reorderable` for an interested
  host; this package's own widget does not offer it.
- **The item template is static.** A `live()` field inside a row does not
  re-settle that row — `/state` settles a flat form, and giving it a row
  coordinate is its own problem. `doctor` names a repeater containing a
  `live()` field so a panel author is not surprised.
- **Relationship repeaters remain unusable this slice**, published read-only
  and reported by `doctor` — honest, but a panel leaning on one gains nothing
  yet.
- **No nested repeaters.** A repeater inside a repeater's item template is
  published (the walker recurses into it like any other child) with
  `config.readOnly: true`, so the client renders it inert, and `doctor`
  reports it. Its rows still round-trip — they are part of the outer array —
  they simply cannot be edited from a phone. Two levels of row coordinate is
  a different problem.
- **A repeater with a non-round-tripping child is refused wholesale.** One
  `Hidden`, one unmapped component, one `disabled()` field or one
  `relationship()->dehydrated(true)` child in the item template costs the
  entire field its editability, because the array is
  written whole and there is no row identity to merge the stored value back
  by. Honest, and it protects the data, but a panel that stamps every row
  with a `Hidden` id gets a read-only repeater on mobile until row identity
  is on the wire.
- **`addable`/`deletable` are client hints, never server rules.** A crafted
  payload can add or remove rows regardless. Use `minItems`/`maxItems` for a
  bound the server enforces.
- **`Repeater::setUp()`'s own default is deliberately withheld everywhere.**
  It unconditionally calls `defaultItems(1)`, whose `default()` override keys
  its one blank item under a freshly generated random UUID on **every**
  evaluation — verified empirically, two successive `/schema` calls for the
  same user produced two different UUIDs for the same field. Publishing that
  through `/schema`'s `default` would move the document's ETag on every
  request for no real change, and feeding it into `FormDefaults` would write
  `{"<uuid>": []}` — a dict, not the list-of-maps shape the design spec
  documents — into the column on any create that never mentions the field.
  Both `SchemaWalker` and `FormDefaults` withhold it by type, so an ordinary
  create leaves the column untouched rather than corrupting it.

## Relations

A resource's `getRelations()` — the same relation managers a Filament panel
already declares — becomes read-only, paginated child lists on mobile. **List
only, this slice:** no create, edit, delete, attach or detach, and the
manager's own filters, search and sorting are ignored — the list is served in
relation order. Nothing is declared to opt this in; every relation manager a
resource's `getRelations()` returns is introspected.

```
GET /api/mobile-panel/{resource}/{record}/relations/{relation}
```

mirrors `index()`: the same `?page=` handling, the same
`{data, meta: {current_page, last_page, per_page, total}}` envelope, the
same `RecordSerializer`, and the same eager loading — the card's dotted
fields drive `->with()`, so `subtitle('company.name')` costs one query for
the page rather than one per row. A client that can render a resource list
can render this with no new parsing.

Neither endpoint honours a client-supplied `perPage`; both use
`config('filament-mobile.per_page')`. Search, sort and the manager's filters
are the deliberate non-mirror, above.

`/schema` gains a `relations` array per resource — **always present**, `[]`
for a resource with none, never an absent key:

```jsonc
{
  "key": "banners",
  "label": "Banners",
  "card": { "title": { "field": "name" }, "subtitle": { "field": "status" } },
  "recordKey": "id"
}
```

- **`key`** is `getRelationshipName()` — what the endpoint above is
  addressed by, never the manager's own class name, which is not URL-safe
  and leaks the host's namespace.
- **`label`** comes from the manager's own title — an explicit
  `protected static ?string $title`, `getRelationshipTitle()`, or a related
  resource's plural label, in that order — falling back to a humanised
  `key` only when reading the title needs an owner record the discovery
  step doesn't have.
- **`card`** is derived from the manager's own `table()` columns: the first
  becomes the title, the second the subtitle, the rest are dropped this
  slice (see Known weaknesses below). Override with
  `MobileResource::relationCard($key, fn (MobileCard $card) => ...)` — the
  same escape hatch `card()` already is — when the derived card is wrong or
  empty.
- **`recordKey`** is the **related** model's own `getRouteKeyName()`, not the
  parent's — routinely a different model with a different key.

A relation the package refuses (see below) is **absent** from `relations`,
not published disabled — the package's standing rule: absence means
unavailable, never a disabled corpse.

### Three gates, each failing closed

1. **The parent's own `show()` authorization — both halves.** The resource's
   class-level `viewAny` *and* the record's own `view`. With only the first,
   an ownership policy that refuses `GET /companies/1` would still hand over
   `/companies/1/relations/banners`, rows and all — and the `200` would
   confirm the row exists into the bargain.
2. **`RelationManager::canViewForRecord($ownerRecord, $manager)`**, passed
   the manager's own class-string. That is not a guess: it's Filament's own
   no-page fallback (`CanAuthorizeAccess`:
   `$this->pageClass ?? static::class`), and it fails in the safe
   direction — an override that branches on a specific page class never
   matches it and falls to its `else`.
3. **The child model's own `viewAny`**, through the same `Authorizer` every
   other gate in this package uses.

**Gate 3 is inside gate 2 by default, not independent of it.** Filament's
default `canViewForRecord` implementation is itself
`authorize('viewAny', $model, ...)`, so an ordinary manager is checked
twice by the same rule. Gate 3 only earns its keep against a panel that
*overrides* `canViewForRecord`, which replaces that check rather than
adding to it. Both are still run, unconditionally.

**Gate 2 runs under guard impersonation, and here is why it must.**
Filament's default `canViewForRecord` resolves its user through
`Filament::auth()->user()` — the **panel's** guard, not the request's.
Nothing on this route rewrites that: `auth:{guard}` middleware moves the
*default* guard (so the bare `Gate` facade does follow the request's user),
but the panel keeps its own, separately configured `authGuard`. Left
ambient, this gate answers about whoever happens to hold a panel session —
measured both ways in this package's own tests: a `403` for a user their
own policy would allow, and a `200` serving rows to a caller because an
unrelated admin's browser session rode along. The second is privilege
escalation. So the request's user is impersonated onto the panel's guard
for exactly the duration of the gate call, and the previous occupant is
restored in a `finally` — in-memory only, no session written, undone even
when the gate throws. **A null request user refuses outright**, before the
guard is touched at all: binding null only clears the guard's cache, and a
session guard then re-reads its own cookie and answers for whoever that
session belongs to, not "nobody" — impersonation cannot enforce a refusal
here, so the controller refuses first.

**A refused or non-existent relation is a 404, not a 403.** A 403 would
suggest it might appear for someone else; a relation this package will
never publish for anyone does not exist as far as this API is concerned.

### The refusal — a narrowed relation is not published at all

**A relation whose table adds query scopes (`->modifyQueryUsing(...)`) is
refused entirely**: absent from `/schema`, its endpoint 404s, and
`filament-mobile:doctor` names it with the reason.

This was verified, not assumed, against Filament in `vendor/`. Outside
Livewire — with a bare, unbooted relation manager, and separately with the
package's own `HeadlessTableHost` — `Table::getQuery()` returns a `Builder`
whose model is `NULL` and whose SQL is `select * where "status" = ?`: the
author's `modifyQueryUsing` closure survived, but the relation binding to
the owner record did not. Two different hosts, the identical unusable
result. The narrowing is detectable — `Table::$queryScopes`, read by
reflection, is non-empty exactly when a table declares one — but not
reproducible: there is no means this package already has to rebuild that
query correctly outside Livewire. Publishing the unnarrowed relation would
list rows the web panel deliberately hides — a data-exposure failure, not a
cosmetic one — so the relation is refused wholesale rather than served
without its scope. A future maintainer tempted to "just fetch the rows
anyway" should re-read this paragraph first.

Also refused, each named by `doctor`:

- a `getRelations()` entry that is not a plain `RelationManager` subclass —
  `RelationGroup` and `RelationManagerConfiguration` are both legal
  Filament entries and neither is handled this slice;
- a relation whose `getRelationshipName()` cannot be read;
- a relation whose relationship does not resolve on the resource's model —
  `protected static string $relationship = 'ghosts'` where nothing on the
  model is named `ghosts`. Checked by *building* the relationship on an
  unsaved instance (building never queries). Unchecked, such a relation was
  published by `/schema` and its endpoint then answered 403, because
  Filament's default `canViewForRecord` cannot resolve the related model
  either — a control that cannot work, filed under "not for you";
- a relation whose columns yield no usable card and for which the host
  declared none;
- a relation whose `relationCard()` **fills no slot** —
  `relationCard('banners', fn ($card) => $card)`. The rule is "no card ⇒ no
  relation", and the test is emptiness, not nullness: an unconfigured card is
  not null, and it serves rows carrying nothing but their record key;
- a `relationCard()` key naming no relation this resource declares — a typo
  like `relationCard('bannerz', …)`. The declaration is inert and the derived
  card is used instead, so the card you wrote was simply never read.

A closure that returns something other than the `MobileCard` it was given —
usually a block body missing its `return` — is refused by `relationCard()`
itself, at declaration time.

### Known weaknesses, stated now

- **A relation manager that narrows its query is invisible on mobile.** For
  some panels that may be most of them — `doctor` names each one, but this
  is the sharpest limitation in this slice and the most likely reason a
  panel gains nothing from it.
- **The `Table::$queryScopes` reflection is a compatibility risk across
  `^4.0|^5.0`**, and it fails **closed**. If the property is renamed on a
  future Filament release, a naive implementation would silently return "no
  scopes" and stop refusing — failing open, in the one place this design
  cannot afford to. A `property_exists()` tripwire refuses every relation
  loudly instead: a test asserts the property exists on both majors and
  fails if it does not, and the production path throws — caught and turned
  into a refusal — rather than guessing when the property is gone. Measured
  by renaming it in `vendor/`: 26 tests red, zero relations published.

  The residual risk is the narrower one: Filament keeping the name and
  moving the mechanism, which the tripwire cannot see. That is caught
  behaviourally instead — the narrowing-refusal tests red — which is why
  both kinds of test exist.
- **The relation manager's filters, search and sorting are ignored.** The
  list is in relation order, unfiltered. A panel whose relation manager is
  only usable with its filters gets a list that is technically correct and
  practically wrong.
- **Only the first two columns become a card.** A relation whose meaning
  lives in its third column looks empty of information.
- **Nothing is writable.** No create, edit, delete, attach or detach.

## Rich text

A `RichEditor`-backed infolist entry stops publishing raw markup and starts
publishing a document. **Read only, this slice:** the form field is still a
`textarea` over the raw HTML string, exactly as it always was — editing a
rich column is out of scope, so nothing about the write path changes and no
existing form test is affected.

```
GET /api/mobile-panel/{resource}/{record}
```

`data.<path>` keeps the raw string, byte-for-byte, exactly as before — the
form prefill still reads it. The two derived shapes travel together on a
flat sibling key:

```jsonc
{
  "id": 1,
  "body": "<p>Hello <strong>world</strong></p>",
  "body.__rich": {
    "doc": { "type": "doc", "content": [/* … */] },
    "text": "Hello world"
  }
}
```

An undotted column name cannot carry two shapes at once — the form wants the
raw string it edits, the infolist wants the document, the card wants plain
text, and that is genuinely three consumers wanting three things from one
column. `withInfolistPaths()` plus `withFormPaths()` on the same name was
measured to collapse to one key (the form pass writes flat and runs last),
so the two derived shapes ride together on `<path>.__rich` instead — the
same flat-sibling convention `caption.ar` already established for
translatables, for the same collision reason. **Absence means unavailable**:
a column with nothing to convert (null, empty, or a conversion that throws)
gets no sibling at all, never an empty one, and every consumer falls back to
the raw string it already has.

An infolist entry becomes `rich_entry` — joining `ComponentTypeMap::REFINED`,
exactly as `badge_entry` does — when either holds:

- the entry called `->prose()`; or
- the resource's model implements `HasRichContent` and
  `hasRichContentAttribute($name)` is true for this column.

Neither answering leaves the entry `text_entry`, which is today's behaviour,
not a broken one — a gate that cannot answer refuses rather than guesses.

### Why it must be Filament's own renderer

Conversion runs the stored column through Filament's own
`RichContentRenderer`, never a bare `new Tiptap\Editor`. This is not a style
preference. Measured on identical input, a bare editor silently drops marks
it has no extension registered for:

```
new Tiptap\Editor        →  {"type":"text","text":" and link"}          ← link mark GONE
RichContentRenderer      →  {"type":"text","text":"link",
                             "marks":[{"type":"link",
                                       "attrs":{"href":"https://x.test"}}]}
```

Both outputs parse. Only one of them is the document the column actually
holds.

**It is a default-configured `RichContentRenderer::make()`, not the panel
component's own `RichEditor::getTipTapEditor()`** — which adds
`->plugins(...)->linkProtocols(...)`. The difference is measurable, and it is
the same silent-content-loss failure one level up:

```
RichContentRenderer::make()                       →  tel:+15551234, https://ok.test
RichContentRenderer::make()->linkProtocols(...)   →  tel:+15551234, myapp://x
```

A panel that registered `myapp` for deep links has those links' marks
dropped by this conversion. It is not fixable at this seam:
`linkProtocols()` is configured on the `RichEditor` **form component** and
lives nowhere else — the model's own `RichContentAttribute::getRenderer()`
carries plugins but no link protocols at all — so honouring it would mean
building a resource's form schema for every record on `index()`, the exact
per-request cost the narrowed `->prose()` promise below exists to avoid, and
it would still leave the `->prose()` half unconfigured. Listed under Known
weaknesses.

The vocabulary is small and closed, measured through `RichContentRenderer`
over every construct a `RichEditor` toolbar offers:

```
NODES: doc, paragraph, text, heading, bulletList, orderedList,
       listItem, blockquote, horizontalRule, image
MARKS: bold, italic, link, strike, underline, code
```

No tables, no custom TipTap blocks. `textAlign` is published on paragraphs
and headings but not yet honoured by the client — it belongs with the
RTL/i18n slice.

### A security property, stated because it is easy to lose

Conversion runs the column through TipTap's own extension whitelist, and
anything outside it — `<script>`, `<style>`, inline event handlers, an
unknown tag, a `javascript:`/`data:`/`vbscript:` href — is dropped. **Neither
half of the `__rich` sibling can carry executable markup regardless of what
is stored in the column**, a stronger guarantee than today's behaviour, where
the raw string reaches the client untouched.

Two things make that true of *both* halves rather than only the document,
and both were defects first:

- **`text` is flattened from the converted document, not from the column.**
  `strip_tags` removes tags but keeps a `<script>` **body**, so flattening
  the raw string published `okalert("pwned")` beside a correctly-sanitised
  document — on the card, which is the most-seen surface in the app. One
  conversion now produces both shapes, so the whitelist governs them by
  construction rather than by two code paths agreeing.
- **A JSON-valued column re-enters through the whitelist.** TipTap treats
  any string that `json_decode`s cleanly as JSON and loads it *unvalidated*
  (its `Schema::apply()` strips marks and never filters node types), so a
  `->json()` column stored as a string published `evilNode`, `onclick` and a
  `javascript:` href verbatim. The JSON path is now re-serialised to HTML and
  reparsed, which is a measured no-op for a legitimate document.

This is a **consequence of the design, not a feature that was built** —
which is exactly why it has its own test, asserted on the whole envelope
rather than on the document alone.

### Cards

`RecordSerializer` — not either controller — produces the `__rich` sibling,
so `index()` and `show()` never disagree about it. A card slot reads
`<path>.__rich.text`: the converted document flattened, then tags stripped,
entities decoded and whitespace collapsed via `PlainText::of()` — the same
routine `allowHtml()` option labels already use, extracted rather than
reimplemented so the two paths cannot drift apart.

**The promise is narrower than "cards never disagree", and stated precisely
because it is easy to overstate:** it holds for a **model-declared** rich
column and not for a `->prose()`-only one.

- `HasRichContent` + `registerRichContent('body')` is a fact about the
  **column**. `RecordSerializer` resolves it from the record itself, so
  every endpoint publishes the sibling with nothing wired, and a card bound
  to that column renders clean text on the list and the detail screen alike.
- `TextEntry::make('note')->prose()` is a declaration about **one infolist
  entry**. It governs that entry, not the card, which is a different
  surface — and `index()` builds no infolist and would have to build and
  walk one on every list request to learn otherwise, a cost it exists to
  avoid.

So a card slot bound to a `->prose()`-only column gets a document on
`show()` and **no sibling on `index()`** — the list renders raw markup next
to a detail screen that renders clean. `filament-mobile:doctor` names
exactly this combination under "Rich text on cards" and says which one-line
model change fixes it:

> `Banner`: card field `note` is rich only because the infolist calls
> `->prose()`, so the list endpoint publishes no `note.__rich` and the card
> renders raw markup — register it on `Banner` with `HasRichContent` to fix
> it

### Known weaknesses, stated now

- **No editing.** The form field stays a `textarea` over the raw string, so
  a user reads formatted text and edits markup. A real editor is a much
  larger build with two runtime dependencies on the client, and today's
  behaviour loses nothing by staying as it is.
- **An unwired host loses link visibility on the client.** See the Dart
  README's Rich text section.
- **No tables, no custom TipTap blocks.** Both fall outside the measured
  vocabulary.
- **Panel-registered plugins and link protocols are not inherited.** The
  conversion uses a default-configured `RichContentRenderer::make()`, so a
  `->linkProtocols(['myapp'])` panel loses those links' marks — see "Why it
  must be Filament's own renderer" for why the component is out of reach
  from this seam.
- **`textAlign` is published and not honoured.** It belongs to the RTL/i18n
  slice.
- **The conversion is uncached and runs per request** — the same caching gap
  `RelationDiscovery::for()` already has, and the same pass will address
  both.

## Dashboard

Opt named `StatsOverviewWidget` and `ChartWidget` subclasses into one
read-only endpoint — the same "invisible until named" safety property every
other feature in this package has. A dashboard widget runs arbitrary
queries, so auto-discovery would serve them to phones the moment someone
adds a widget to the web dashboard; this package never does that.

```php
// config/filament-mobile.php
'widgets' => [
    App\Filament\Widgets\OrdersOverview::class,
    App\Filament\Widgets\RevenueChart::class,
],
```

Order is the array's order, and it is the publication order. **Only
`StatsOverviewWidget` and `ChartWidget` subclasses are supported.**
`TableWidget`s (a table widget is a resource list with extra steps — the
list endpoint already serves that shape better), custom `Widget` subclasses
with hand-written Blade views (no data contract to read), widget
filters/forms, and per-widget polling are not — `filament-mobile:doctor`
reports a configured class that is any of those.

```
GET /api/mobile-panel/dashboard
```

```jsonc
{
  "widgets": [
    {
      "type": "stats",
      "heading": "Store overview",
      "description": "Orders at a glance",
      "stats": [
        {
          "label": "Orders this week",
          "value": "1,340",
          "description": "12% increase",
          "descriptionIcon": "heroicon-m-arrow-trending-up",
          "color": "success",
          "chart": [7, 12, 9, 15, 22]
        }
      ]
    },
    {
      "type": "chart",
      "heading": "Revenue",
      "description": "Last 12 months",
      "chartType": "line",
      "labels": ["Jan", "Feb", "Mar"],
      "datasets": [{ "label": "Revenue", "data": [120, 340, 210] }]
    }
  ]
}
```

Deliberately not part of `/schema`: a widget's values are computed **per
request** — every read runs its queries — so they do not belong in a
document whose whole value is being static and cacheable. A host with no
dashboard pays nothing for this endpoint's existence, and pull-to-refresh
re-runs the queries, which is what a dashboard wants anyway.

- **Authorization is the widget's own `Widget::canView()`**, called per
  request per user — the same static the web dashboard consults. There is
  no resource-level gate here, because there is no resource.
- **A broken widget degrades; it never 500s the dashboard.** A `canView()`
  that throws, a widget that cannot be constructed, a `mount()` that
  throws, and a data method that throws are all indistinguishable on the
  wire — the widget is simply absent from `widgets`. Reasons surface only
  in `_warnings` (non-production) and `filament-mobile:doctor`, never to
  the client. One bad query must never take every user's dashboard down.
- **`mount()` is invoked**, not just the constructor. `ChartWidget::mount()`
  is a real Livewire lifecycle hook a real widget can depend on (it seeds
  `$filter` and similar state), and plain `new $class()` never calls it —
  construction alone is not the whole lifecycle.
- **`value` is always a string.** `Stat::getValue()` is `mixed` — a panel
  returns ints, floats, `Number::abbreviate()` output, money strings. The
  phone cannot know the panel's formatting intent, so the server
  stringifies once; a value object's `__toString()` is honoured, and
  something genuinely unrenderable becomes `null` and warns, never a
  silent `""`.
- **`datasets` is normalised, not Chart.js passthrough.** Only `label` and
  a numeric `data` list are published; a dataset without numeric `data` is
  dropped with a warning rather than shipped as "whatever Chart.js
  accepts". Numeric *strings* count — MySQL PDO returns strings for
  `DECIMAL` aggregates, and they publish as floats. An empty series (zero
  rows this period) publishes as `[]`, a normal state, without a warning.
- `widgets: []` is a valid, ordinary answer — not a 404 — for a panel with
  no opted-in widgets, or a request where every configured widget was
  denied or broke.

**Known weakness: one endpoint means one slow widget slows the whole
response.** Every configured widget's queries run inside the same request;
there is no per-widget fetch. A per-widget endpoint was considered and
rejected for N-requests-per-open — if a real panel's dashboard shows this
hurting, the fix is a second endpoint added beside this one, not a
replacement for it.

## Locale and direction

`/schema`'s `panel` block carries two more keys:

```jsonc
{
  "version": 1,
  "panel": {
    "id": "mobile",
    "title": "Acme Admin",
    "locale": "ar",
    "direction": "rtl"
  },
  "resources": []
}
```

`GET /dashboard` publishes the same `direction` key beside `widgets`, for
the same reason `/dashboard` exists as its own endpoint at all: it carries
no schema for a client to read a direction off of.

**`direction` is Filament's own answer, not a locale table this package
maintains.** It is `__('filament-panels::layout.direction')` — the exact
key the web panel itself lays out with — normalised to exactly `ltr` or
`rtl`. The phone and the panel agree by construction: there is no mapping
from locale codes to direction for this package to keep current as
Filament ships more locales.

**Read the namespace carefully — this is the one part of this feature that
fails with no error.** The key lives under the **`filament-panels`**
namespace, not `filament::`. Measured against `filament/filament` 5.7.5:
`filament-panels::layout.direction` resolves to `'ltr'`/`'rtl'` for every
one of its 62 shipped locales; `filament::layout.direction` **does not
resolve at all** and returns the raw key string back. Get the namespace
wrong and every panel silently renders `ltr` forever — no exception, no log
line, nothing that flags it as broken. It is one character class away from
correct.

The fallback chain is deliberately generous, because a translation call is
not something `/schema` can afford to let fail the whole document:

- **A locale Filament does not ship** falls back to `ltr` through
  Filament's own translation chain — `__()` never returns the bare key for
  this one, so no guard is needed on this package's side either.
- **A panel that overrode the key with something other than `'ltr'` or
  `'rtl'`** — a typo, a placeholder string — normalises to `ltr`. The
  contract is a closed set; a value no client can act on is treated the
  same as no value at all.
- **A translator that throws** (a custom `Translator` binding, a broken
  language file) degrades to `ltr` rather than 500ing `/schema`. This is
  the same per-property degradation the rest of the document already
  applies to a broken accessor — one bad translation must not take the
  whole panel down.

`locale` is `app()->getLocale()`, published for a client that wants it for
its own formatting; this package does not use it to decide `direction` —
that is read from its own key, independently.

**The `/schema` `ETag` needed no change for any of this, and that is worth
stating so nobody "fixes" it later.** The ETag is a `sha1` hash of the
content actually built (see Schema caching, below) — once `locale` and
`direction` are in the document, they are already inside the hash. A panel
whose locale changes gets a new ETag for free, the same way a changed label
already does.

## Schema caching

`GET /api/mobile-panel/schema` sends an `ETag` on every response — a weak
validator, `W/"<sha1 of the built document>"`. A request that sends a
matching `If-None-Match` gets back a `304` with an **empty body**, still
carrying the `ETag`.

```
GET /api/mobile-panel/schema
If-None-Match: W/"a1b2c3..."

HTTP/1.1 304 Not Modified
ETag: W/"a1b2c3..."
```

**The hash is taken before `_warnings` is attached, and that is
deliberate.** `_warnings` is a dev-only, environment-dependent field —
present outside `production`, absent inside it — and no part of the
contract. Hashing it in would move the ETag between environments for a
document that is otherwise identical, so a client's cache built against
`local` would revalidate to a full `200` in `production` for no real
change. Hashing what actually defines the document, and nothing else, is
what makes the ETag stable across environments and unstable exactly when
the document itself changes.

**A content hash, not anything derived from identity.** `/schema` is
filtered by policy, so two signed-in users legitimately see different
documents — hashing what was actually built gets that right without this
endpoint knowing anything about who is asking: two users who happen to see
the same resources get the same ETag and both cache correctly; a policy
change that alters one user's document changes their hash and leaves
another's alone.

**`If-None-Match` is read tolerantly**, matching a comma-separated list, and
either the weak (`W/"…"`) or strong (`"…"`) form of the ETag — a proxy along
the way may rewrite either. `*` matches unconditionally, per RFC 7232 §3.2.

**Known cost, stated plainly: a `304` saves bandwidth, not server CPU.** The
document is still built in full to compute its hash — there is no
server-side cache of the built document itself (that is the separate,
deliberately-deferred win; see the design spec's Scope section for why it
was ruled out this slice: it saves CPU but adds invalidation questions —
deploys, policy changes — that ETag/`If-None-Match` does not carry). A
client that persists the document and revalidates with `If-None-Match`
saves the ~200 KB transfer on an unchanged panel; it does not make `/schema`
itself cheaper to serve.

**An unencodable document fails loudly, not silently.** `json_encode()`
returns `false` on invalid UTF-8 (a realistic trigger: a translated label
with a bad byte sequence), and hashing `(string) false` — `''` — would give
every failing document the same ETag, so two genuinely different broken
documents would collide and a client could keep a stale panel forever with
no way to notice. This endpoint throws instead.

See `dart/filament_mobile/README.md`'s Schema caching section for the
client half: the host-supplied `FilamentSchemaCache` and
`FilamentConditionalTransport` ports, cold-start-render-then-revalidate, and
the cache key's per-user scoping obligation.

## Authorization

The panel's existing policies are the only permission model — there is no
second one. A resource the user cannot `viewAny` is **absent** from `/schema`,
not merely flagged. Filament's semantics are followed exactly, including the
no-policy case (permitted, but `Gate::before` still applies), so mobile is
never looser than the web panel.

Resource-level `permissions` report *capability*; the per-record truth travels
with each record on the detail endpoint, evaluated against the real model.

## `php artisan filament-mobile:doctor`

Reports which resources are exposed, which components could not be walked,
drift between `mobile()` and `table()`, and card paths that resolve to nothing.
Exits non-zero on anything actionable, so CI can gate on it.

**In a policy-guarded panel, pass `--user`.** By default `doctor` builds the
panel document as an *anonymous* user, so `viewAny` denies everything, every
section reads `(none)` because nothing was inspected — not because nothing is
wrong — and the run can never go green:

```bash
php artisan filament-mobile:doctor --user=1
php artisan filament-mobile:doctor --user=admin@example.com
```

The id or email is resolved through the provider behind the guard in
`filament-mobile.guard`, so doctor sees exactly what that user's phone would.
This is the form to run in CI. Without it, resources are listed under "Not
inspected" and the command still exits non-zero.

## Configuration

```php
return [
    'prefix' => 'api/mobile-panel',
    'per_page' => 20,     // not client-controllable, by design
    'guard' => null,      // null = application default; see the warning above
    'resources' => null,  // null = read the registered panel
    'widgets' => [],      // dashboard widgets, by class name, in publication order
];
```

`resources` is an explicit opt-out of panel discovery — useful where no panel is
booted, or to serve a deliberate subset.

## Known limitations in this release

Measured against a real 35-resource production panel.

- **91.7% of components walk cleanly.** The rest emit a warning and are omitted
  — never silently dropped. `Schemas\Components\Livewire` is out of scope.
  (86.4% was the rate *before* `CheckboxList` was mapped; see the pilot's
  §10.) A single-file `FileUpload` now walks as an editable field —
  see the Upload section above; only `FileUpload::multiple()` remains out of
  scope. `Repeater` now walks as an editable field too — see the Repeater
  section above; only `Repeater::relationship()` remains out of scope.
  `RichEditor` now walks too — a `textarea` on the form always (see Rich
  text above), and `rich_entry` on the infolist where `->prose()` or the
  model's own `HasRichContent` says the column is rich. None of the
  percentages above has been re-measured against the pilot panel since these
  two shipped.
- **A media-library image on a card serialises as `null`.** `leadingImage()`
  needs a real attribute; there is no way to name a media collection yet.
- **Badges carry the raw value**, not the formatted label. Supply the colour map
  yourself via `badge($field, $colors)`; the label is the client's job.
- **`hidden` means different things on the two endpoints.** `/schema`'s value
  is an empty-form snapshot and a **first-paint hint only**; `/state`'s value
  is **authoritative**, evaluated against real submitted values. A client may
  use `/schema`'s `hidden` to avoid rendering an obviously conditional field on
  first paint, but must not treat it as truth.
- **`DELETE` runs the model's delete, not Filament's `DeleteAction`.** So a
  resource's `DeleteAction::before()`/`after()` hooks and its
  "restrict if related records exist" guard do **not** run for a mobile delete
  — those live on the action, which is a Livewire construct this package does
  not host. Model observers, soft deletes and policies are unaffected: they
  hang off `$record->delete()` and run exactly as they do in the panel. The
  pilot measured 1 of 33 resources affected (a `before()` hook with an
  external side effect, so a mobile delete skips it). If a
  resource depends on an action hook, move that logic to an observer and both
  panels get it.
- **Multi-valued relationship fields sync, with one deliberate asymmetry.**
  `Select::multiple()->relationship()` and `CheckboxList->relationship()` are
  saved through Filament's own `saveRelationships()` after the attribute
  write. A key **absent** from the payload leaves the pivot untouched; an
  explicit `[]` clears it — absence is not emptiness, so a partial PUT never
  wipes a relation it did not mention. A disabled relation field refuses
  both ways: crafted ids neither attach nor degrade into a clearing sync.
  Singular relationship **containers** (`Section::make()->relationship()`)
  are still not saved and stay published `disabled: true`.
- **Dates are ISO-8601 UTC.** The panel's display format does not travel.
- **`filters` is always `[]`.** Table filters are not introspected.
- **Tabs are flattened to sections** — deliberately; tabs are a poor phone
  control. A `Tabs` container's own name may surface as a label.
- **`helperText` is always `null`.** Filament exposes no public getter for it on
  either major, so the contract's field is emitted and never filled. A real
  contract gap, not a walker bug: helper text does not reach the phone.
- **An `IconEntry` becomes a `boolean_entry`.** The contract vocabulary is
  frozen and has no icon type; a boolean icon entry additionally carries its
  true/false icons and colours in `config`.
- **A resource's validation messages travel, so a host does not translate
  them.** Every constrained field's `rules` carries a `messages` map in the
  panel's locale, generated through the same Laravel validator translation a
  `422` for the same submission would produce — see `contract/README.md`. A
  host that translates nothing still shows an Arabic hint in front of an
  Arabic panel; `FilamentStrings` remains only as the client's per-rule
  fallback for a rule the server sent no message for.
- **A crafted payload that flips a gate closed can silently discard a
  legitimate write.** `store()`/`update()` settle the schema against trusted
  state before extracting rules (`Write\SettledSchema`), and the allow-set
  only ever shrinks — so a stored `kind='unlock'` (a `Hidden`, gate open) with
  a PUT of `{"kind":"promo","gate_note":"..."}` gets a `200` with `gate_note`
  discarded rather than a clear rejection. Reachability is low: a client never
  learns `kind`, so it has to invent a contradicting value for a field it was
  never shown. See the `ponytail:` note at
  `MobilePanelController::allowedRules()`.
- **A form with an unreasonable number of chained gates can 500 on
  `/state`.** `SettledSchema`'s fixpoint is bounded at 32 passes and fails
  closed rather than write against state it cannot vouch for; that bound is
  not a class invariant but roughly the count of writable field names, so a
  form with more than 31 chained gates could hit it legitimately. See the
  `ponytail:` note at `Write\SettledSchema::settle()`.
- **A top-level `->hidden()->dehydratedWhenHidden()` field is dropped by the
  write path, though it is genuinely writable.** `/state` reports it because
  it walks with `getComponents(withHidden: true)`; `store()`/`update()` do not,
  so the field never reaches `SettledSchema` or the rule extractor and a
  submitted value for it is silently never written. Exotic shape; see the
  `ponytail:` note at `MobilePanelController::formComponents()`.

## Testing

```bash
vendor/bin/pest
```

CI runs the suite against Filament 4 and 5, and separately against MySQL — a
`LIKE ... ESCAPE` clause once passed on SQLite and failed on MySQL, so one
driver is not enough.
