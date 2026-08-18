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
| `GET /api/mobile-panel/{resource}/{record}/relations/{relation}` | One relation manager's child rows, same envelope as the list |
| `POST /api/mobile-panel/{resource}/{record}/relations/{relation}` | Create a child row through the relationship, validated from the child resource's own form |
| `PUT /api/mobile-panel/{resource}/{record}/relations/{relation}/{child}` | Update a child row, same validation |
| `DELETE /api/mobile-panel/{resource}/{record}/relations/{relation}/{child}` | Delete a child row — 200 with the deleted row, deliberately not 204 |
| `POST /api/mobile-panel/{resource}` | Create, validated from the resource's own schema |
| `PUT /api/mobile-panel/{resource}/{record}` | Update, same validation |
| `DELETE /api/mobile-panel/{resource}/{record}` | Delete, gated on the record's own policy |
| `POST /api/mobile-panel/{resource}/state` | Re-evaluate the schema against submitted values, for reactive forms |
| `GET /api/mobile-panel/dashboard` | The panel's opted-in dashboard widgets, values computed live |

Every write is authorised through the panel's own policies, and its validation
rules are extracted from the same schema the read path publishes — so the rules
a client is shown and the rules the server enforces cannot drift apart.

![How it works](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/diagram.png)

And this is what the panel becomes — the client's stock screens, driven entirely by what this package publishes:

![The screens](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/showcase.png)

---

## Install

**Requires PHP 8.4+**, Laravel 12 and Filament 4 or 5. The PHP floor is this
package's own — Filament itself allows 8.2 — and it is deliberately the version
this package is developed on, because a feature above the floor is a parse error
rather than a degradation and the old `^8.2` promise had already been broken
without anyone noticing. On PHP 8.2 or 8.3, pin `^0.6.1`.

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
| `relationSearchable(string $relation, array $columns)` | Per-relation `searchable()` — same semantics, plain columns only |
| `relationSorts(string $relation, array $labels)` | Per-relation `sorts()` |
| `relationDefaultSort(string $relation, string $key, string $direction = 'asc')` | Must be one of the declared `relationSorts()` keys |

## What ships

| Feature | What it gives you |
|---|---|
| [Resources and cards](#opt-a-resource-in) | Opt in per resource; the card is declared, the form and infolist are read from the resource |
| [Actions](#actions) | The panel's own record actions, published per record with their authorization already applied |
| [Upload](#upload) | Single- and multi-file `FileUpload` / `SpatieMediaLibraryFileUpload`, with the field's own accept and size rules enforced per file |
| [Repeater](#repeater) | JSON-column repeaters, validated per row |
| [Radio](#radio) | Real radio buttons, sharing `Select`'s own options |
| [Toggle buttons](#toggle-buttons) | `ToggleButtons` — single or multiple, always inlined, never a search URL |
| [Slider](#slider) | Single or range slider, bounds published and enforced |
| [Tags](#tags) | Free-form string tags, per-tag rules enforced, a configured separator mirrored into the stored column |
| [Key/value](#keyvalue) | Free-form key-value pairs, gated by four client hints |
| [Colour](#colour) | `ColorPicker` in the format the panel declared, never converted |
| [Time and date bounds](#time-and-date-bounds) | `TimePicker` as its own type, and the `minDate`/`maxDate` a picker declares |
| [Relations](#relations) | Relation managers as child lists — writable when exactly one resource owns the child model, searchable and sortable where the host declares it |
| [Rich text](#rich-text) | `RichEditor` columns as a structured document, sanitised by construction |
| [Dashboard](#dashboard) | The panel's opted-in widgets, computed live |
| [Locale and direction](#locale-and-direction) | The panel's own locale and `ltr`/`rtl`, so the phone lays out the way the panel does |
| [Schema caching](#schema-caching) | An ETag on `/schema` so a cold start is one conditional request |

**Which fields work?** See [Supported form inputs](#supported-form-inputs) — the table to check before pointing this at a panel.

Everything below is reference. Read the section for the feature you are wiring;
each ends with a **Known weaknesses** list stating plainly what it does not do.

## Supported form inputs

![Form field types](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/inputs.png)

The one table to check before pointing this at a panel. **A field whose
component is not here is dropped** — the walker reports it as
`unsupported component type`, `doctor` names it, and, because a dropped field
gets no validation rule either, a `NOT NULL` column behind one fails at the
database rather than at validation.

| Filament component | Wire type | Notes |
|---|---|---|
| `TextInput` | `text` | refines itself to `email`, `password` or `number` from its own accessors |
| `Textarea` | `textarea` | |
| `Select` | `select` | `multiselect` when `->multiple()`; a searchable relationship select publishes an `optionsUrl` instead of inlining |
| `Radio` | `radio` | shares `Select`'s own options; always inlines, never falls back to a search URL |
| `ToggleButtons` | `toggle_buttons` | same options shape; `->multiple()` publishes `multiple: true`; always inlines, never an `optionsUrl` |
| `CheckboxList` | `multiselect` | |
| `TagsInput` | `tags` | per-tag rules enforced; a `->separator()` is mirrored into the stored column |
| `KeyValue` | `keyvalue` | four gates published as **client hints**, not enforced on write |
| `Toggle` | `toggle` | |
| `Slider` | `slider` | single or range; range mode is detected from an array `->default()` on `/schema` (see [Slider](#slider)) |
| `Checkbox` | `checkbox` | |
| `DatePicker` | `date` | publishes `minDate` / `maxDate` / `seconds` |
| `DateTimePicker` | `datetime` | same, plus `hoursStep` / `minutesStep` / `secondsStep` when `> 1` |
| `TimePicker` | `time` | same; a bound may be a bare `09:00` or a full datetime |
| `ColorPicker` | `color` | in the format the panel declared, never converted |
| `FileUpload` | `file` | single or `->multiple()`; a multiple field's value is a List of stored paths, count bounds enforced on write |
| `SpatieMediaLibraryFileUpload` | `file` | same |
| `RichEditor` | `textarea` | **edited as raw HTML**; it renders as a document on read (see [Rich text](#rich-text)) but editing is still markup |
| `Repeater` | `repeater` | JSON-column or `->relationship()`; only when **every** child round-trips |
| `Hidden` | — | deliberately skipped from the wire; its `->default()` still applies on create |
| `Placeholder` | `text_entry` | display-only; publishes as the existing entry type, admits no rule, and renders nothing in a form |

Layout components pass through as containers: `Section`, `Grid`, `Tabs`,
`Tabs\Tab` (flattened to a section — tabs are a poor control on a phone) and
`Fieldset`.

### Not supported

`Builder`, `CodeEditor`, `MarkdownEditor`, `ModalTableSelect`, `MorphToSelect`,
`OneTimeCodeInput`, `TableSelect`, `ViewField`.

`ViewField` is on this list **by design, not by omission**: it is an arbitrary
Blade view with a state path and no introspectable data contract — the same
ruling the P5 custom-Blade-widget decision already made — so there is nothing
to publish. It keeps the ordinary unmapped treatment: dropped with a warning,
named by `doctor`, rule withheld so its state is discarded on write.

**There is an escape hatch.** `config('filament-mobile.types')` maps any
component class onto a type this contract already defines, and host entries win
over the built-ins:

```php
// config/filament-mobile.php
'types' => [
    \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::class => 'text',
    \Filament\Forms\Components\MarkdownEditor::class => 'textarea',
],
```

That is how the pilot panel handled its phone-input and icon-picker plugins.
The constraint is that the value must be a type the contract already defines —
you can point a new component at an existing renderer, but you cannot invent a
new one without a change to this package.

### Rule hints travel with the field — and are enforced

`->url()`, `->regex(...)` and `->confirmed()` publish `rules.url: true`,
`rules.regex` — the pattern **verbatim**, because a rewritten pattern could
disagree with the server's — and `rules.confirmed: true`, and the write path
enforces all three: `RuleExtractor` emits the matching Laravel rules, so a
field that 422'd on the web panel no longer sails through mobile. Mobile
looser than web is the one direction this package's validation can never
drift. `confirmed` is the only one of the three with no Filament accessor:
`->confirmed()` registers an ordinary `rule('confirmed', ...)`, so both the
walker and the extractor detect it by scanning the field's own resolved
`getValidationRules()`. The walker's scan is a **silent probe**
(`declaresConfirmed()`), deliberately not the guarded `read()` every other
accessor goes through — a component whose rule list cannot resolve headlessly
(a `Select` whose `in:` rule needs relationship context) throws as an
*ordinary* event here, and a warning about a probe is noise, not a defect.

**A rule-message translation failure degrades per-field.** The `messages`
map is generated through the same translator the `422` uses; a translator
that throws costs that one field's messages — the client falls back to its
own strings per rule — never the component, and never the document.

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

A `FileUpload` (and `SpatieMediaLibraryFileUpload`) field is editable from
a phone, single or `->multiple()`. `config.multiple` is always present on a
`file` node; a multiple field additionally publishes `maxFiles`/`minFiles`
when it declared them, and its value everywhere — `/state`, a record, a
write body — is a `List<String>` of stored paths.

**Multiplicity does not change this endpoint's shape — one file per
request.** A multi-file field is served by N calls: the client picks one
file, uploads it through the endpoint below, and appends the returned path
to the field's list, once per file. Every per-file enforcement below
(content-sniffed type, size, extension clamp, the component's own
disk/directory/visibility) applies to each call exactly as it does for a
single-file field.

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
saves it — no change to `store()`/`update()`.** This mirrors Filament's own
web panel, which also stores on pick, not on save. `RuleExtractor` admits a
file field's rule — single or multiple — so the stored path(s) enter the
validated payload exactly like any other column value. A multiple field's
rule is `array`/`list` plus count-semantics `max:{maxFiles}`/
`min:{minFiles}` when declared (Laravel's `min`/`max` on an array count its
elements), with a per-element `string` under `name.*` — so a crafted
`[1, 2]` 422s keyed `attachments.0`, and the count bound is the server's
rule, not a client hint. Removal is wholesale-replacement, the
relationship-repeater model: a submitted list is the whole new set, a
submitted empty list clears the column, and an unmentioned field is
untouched. A field whose `isMultiple()` closure throws keeps its rule
withheld — the closed answer the schema walker and this endpoint's
resolver both share. The flip side: a file column accepts **any**
string (or list of strings) through the ordinary write path — matching the
web panel's own Livewire-tamperable property — so a host must not feed that
column to `Storage::download()`/`Storage::url()` (or any path-sensitive
sink) unchecked.

**Orphaned files accumulate.** A user who picks a file and abandons the form
leaves a stored file with no row pointing at it — the same property
Filament's own temporary-upload directory has, and a multiple field makes
it more frequent, not different: one abandoned form can strand a whole
list. This package does not clean
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

**Two things earn `readOnly: true`**, and the write path refuses on the
same two predicates, so the published flag and the server's answer cannot
disagree:

1. **A nested repeater** — a repeater inside another repeater's item
   template. Two levels of row coordinate is a different problem, and a
   nested row's `422` comes back keyed `outer.0.inner.1.x`, which the client
   has no field to render it against.
2. **A repeater whose item template holds a child that would not
   round-trip** — a `Hidden`, an unmapped component type, a `disabled()` or
   never-dehydrated field, or a **relation-write
   child whose `->dehydrated(true)` puts it back into the row's stored
   state**. See below; this is the one that would otherwise lose data.

A **relationship repeater** (`->relationship()`) is **editable** — its rows
write through Filament's own `saveRelationships()`, below — with one refusal
carried over from when it was read-only: a relationship gate that cannot
answer (`getRelationship()` throwing, or a component with no such accessor at
all) still publishes `readOnly: true` with a warning, never an editable
control the write path would silently drop. A gate that cannot answer never
admits — same shape as an upload field's `accept`/`maxSize` gates.

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

**A relationship repeater writes through Filament's own machinery.**
`Repeater::relationship()` registers its own `saveRelationshipsUsing()`
(`Repeater::saveToRelationship()`), and the write path's relation pass
(`Write\RecordForm::saveRelations()`) reaches it unchanged — the same call
Filament's own `CreateRecord`/`EditRecord` make after the attribute save,
not new code. The caveat is row identity: a repeater's state on the wire is
keyless, so every save is **delete-all-then-recreate** — Filament deletes
the existing child rows and re-creates them from the submitted state. That
is pinned in `RepeaterWriteTest`, and it matters for a panel whose child
rows carry ids other tables point at, or timestamps anyone reads. The field
still has no column of its own, so nothing reads one — but its **rows are in
the record payload**, published off the relationship and projected onto the
item template's declared fields, so the edit form prefills and a save that
touched another field round-trips them unchanged. A child's `id`, timestamps
and pivot columns stay off the wire, exactly as an undeclared column does
anywhere else, and zero rows publish `[]` rather than nothing. The write path
also refuses to read a present `null` as a clear; only an explicit `[]`
clears. Both halves matter: while the rows were withheld from a field the
schema published *writable*, a client had no value to send, submitted `null`,
and every child row was deleted behind a `200`.
`filament-mobile:doctor` no longer reports a resolvable
relationship repeater at all; it still reports the shapes this slice
legitimately does not support: a repeater containing a `live()` field (the
item template is static — see below), a nested repeater (published
`readOnly: true`; two levels of row coordinate is a different problem), a
repeater with a child that would not round-trip (named child and all), and
a relationship repeater whose gate cannot answer.

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

**Remote options work inside a row.** A searchable relationship select in an
item template publishes its `config.optionsUrl` like any other over-cap
select, and `POST /{resource}/options` descends **through** a repeater into
its item template to find the field (`OptionsController::findSelect()`) —
the client renders a row's select off the template and asks for it by its
bare child name, so a lookup that stopped at the repeater's border would 422
a node the schema itself published.

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
- **A relationship repeater's save is delete-all-then-recreate.** Keyless
  state leaves Filament's `saveToRelationship()` no row to diff against, so
  every save deletes the existing child rows and re-creates them from the
  submitted state — pinned in `RepeaterWriteTest`. Fine for rows nothing
  else references; a real consideration for child rows other tables point
  at by id, or for `created_at` timestamps anyone reads.
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

## Radio

`Radio::make('plan')->options([...])` is a working, editable field on the
phone, rendered as real radio buttons rather than a dropdown.

```php
Radio::make('plan')->options([
    'monthly' => 'Monthly',
    'yearly' => 'Yearly',
]);
```

**The server side of this is nearly free, because `Radio` shares `Select`'s
option machinery.** Both use `Concerns\HasOptions` — the same trait, the same
`getOptions()` — measured against `filament/filament` in `vendor/`, not
assumed. The walker's existing option reader and `flatOptions()` apply to a
`radio` node unchanged; only the Flutter-side rendering is new.

**One hazard, found and closed: a radio can never use the search-endpoint
fallback, so it must never be offered one.** `select`/`multiselect` degrade
past `options_inline_max` options to `config.optionsUrl`, publishing an async
search affordance instead of the full list. A radio has no
`Concerns\CanBeSearchable` and nothing to post a query to — so before the
fix, an over-cap radio hit the same branch and published an `optionsUrl` a
client could never call, silently dropping every option past the cap with no
way to reach the rest. The inline-cap branch in `SchemaWalker::config()` is
now guarded `$type !== 'radio'`, so an over-cap radio always inlines its full
option list instead.

**No new `RuleExtractor` rule.** An earlier draft of the design spec assumed
`Radio` would get the same `in:` constraint `select` already has; `select`
produces no such rule for any option-bearing field today, so there was
nothing to give `Radio` parity with. Left as-is.

### Known weaknesses, stated now

- **`Radio::isInline()` is ignored.** Options always stack one per row —
  the right treatment on a phone regardless of what the panel configured.

## Toggle buttons

`ToggleButtons::make('status')->options([...])` is a working, editable field
on the phone — the button-row sibling of a radio group.

```php
ToggleButtons::make('status')->options([
    'draft' => 'Draft',
    'live' => 'Live',
]);

ToggleButtons::make('flags')->multiple()->options([...]);

ToggleButtons::make('active')->boolean();
```

The node shares the option machinery outright — `ToggleButtons` uses the same
`Concerns\HasOptions` trait and `getOptions()` as `Select` and `Radio`
(measured in vendor), so the walker's option branch reads it unchanged,
widened rather than copied:

```jsonc
{ "type": "toggle_buttons", "name": "status",
  "rules": { "required": true },
  "config": { "multiple": false,
    "options": [ { "value": "draft", "label": "Draft" } ] } }
```

**`config.multiple` is always present**, a stated gate like a repeater's
`readOnly`: a single field's value is a scalar, a `->multiple()` field's a
`List` — the `select`/`multiselect` split, through the ordinary write paths.
**`optionsUrl` never appears** on this type, however long the list: like a
radio it has no `CanBeSearchable` and nothing to post a query to, so an
over-cap field inlines every option. The `boolean()` preset needs no
special-casing — it publishes options `1`/`0` and the value travels as
declared.

### Known weaknesses, stated now

- **Per-option colors, icons, tooltips and disabled state are not on the
  wire**, and neither are `inline`/`grouped`/`hiddenButtonLabels` — all
  presentation. A disabled option is still enforced server-side by the `in:`
  rule Filament builds from the enabled option keys.

## Slider

`Slider::make('rating')->range(0, 10)->step(1)` is a working, editable field
on the phone — single thumb, or two thumbs when the field is a range.

```php
Slider::make('rating')->range(0, 10)->step(1);

Slider::make('price_range')->range(0, 100)->step(5)->default([20, 40]);
```

```jsonc
{ "type": "slider", "name": "rating",
  "rules": { "required": true, "numeric": true, "min": 0, "max": 10 },
  "config": { "min": 0, "max": 10, "step": 1, "multiple": false } }
```

**The bounds are enforced, not just hinted.** `Slider::setUp()` force-registers
`required`, `numeric` + `min:`/`max:`, and `integer`/`multiple_of:{step}` on
the component itself (measured in vendor) — behind rule closures keyed off raw
state, which the ordinary accessor reads cannot see — so `RuleExtractor`
re-derives the single-slider bounds from `getMinValue()`/`getMaxValue()`/
`getStep()` (the WithPadding variants first, so `rangePadding` folds into the
enforced bound rather than double-counting), and `array`/`list` for a range.
A range's per-element rules ride the existing `name.*` nested-recursive
machinery. The walker's published `rules` hints read the same accessors, so
hint and gate cannot drift.

**Range mode is detected from state, and `/schema` has none.** Filament's
`isMultiple()` is `is_array($this->getRawState())` — there is no `multiple()`
method — and the `/schema` walk is deliberately unseeded, so the walker falls
back to `is_array(getDefaultState())`: declare the range with an array
`->default([20, 40])`, as above, or `/schema` publishes `multiple: false`
while the rules still say `array`. `/state` re-answers from real state, and a
client never blocks a submission on the hint. A string step publishes no
`step` key at all — absence means "any step".

### Known weaknesses, stated now

- **A range slider with no array default publishes `multiple: false` on
  `/schema`** — the detection weakness above, documented rather than fixed,
  because seeding the schema host's state to fix it would cost more than the
  hint is worth.
- **Pips, tooltips, behavior, fillTrack, vertical, rtl, nonLinearPoints,
  minDifference/maxDifference and decimalPlaces are not on the wire.** The
  first several are presentation; the difference constraints are JS-side
  behaviour even in Filament.

## Tags

`TagsInput::make('labels')` is a working, validated, editable field on the
phone — a free-form list of strings with optional suggestions.

```php
TagsInput::make('labels')
    ->suggestions(['urgent', 'billing'])
    ->nestedRecursiveRules(['max:20']);
```

publishes a `tags` node:

```jsonc
{
  "type": "tags",
  "name": "labels",
  "config": { "separator": null, "suggestions": ["urgent", "billing"] }
}
```

**The value is a `List<String>` on the wire in every case — separator or
not.** `splitKeys`, `tagPrefix` and `tagSuffix` are deliberately withheld:
a tag commits on submit only, and prefixes/suffixes are presentation this
slice does not reproduce.

### The separator mirror — the one place this package reproduces Filament's dehydration

`TagsInput::make('labels')->separator(',')` changes what the **panel**
stores: Filament's own `dehydrateStateUsing()` joins the submitted array
into `"a,b,c"` before it reaches the column, and `hydrateTags()` explodes it
back on read. This package's write path deliberately never runs Filament's
dehydration for anything else — it writes `validated()` straight to the
model — so without a deliberate exception, a client sending an array to a
separator-configured field would store an array where the panel writes a
delimited string: two surfaces, two shapes, one column.

The fix is a narrow, stated exception rather than a new general capability.
`TagSeparators::dehydrate()` joins a separator-configured field's submitted
array with that separator, run on the final attribute array — **after**
`fillMissingPaths()`, not on `validated()` alone, because `TagsInput` ships a
`[]` default through `setUp()` that reaches every create via `FormDefaults`,
and joining before that default is filled in throws on every create that
never mentions the field. Both `store()` and `update()` call the same
function; there is one transform, not two copies that could drift.

The inverse — un-joining a stored delimited string back into an array for a
client to read — lives in `RecordSerializer::hydrate()`, the single place
every serialised record passes through. That is deliberate: **six** read
seams share this one answer — `index()`, `show()`, the `store()` `201` body,
the `update()` `200` body, `RelationController`'s relation rows, and any
future endpoint that serialises a record, because the un-join is baked into
`RecordSerializer::serialize()` itself rather than wired per call site. A
related row's owning resource — needed to know whether *that* row's own
`tags` fields are separator-configured — is resolved through
`ResourceRegistry::findByModel()`, which returns `null` unless **exactly
one** opted-in resource maps to the model class: zero matches or an
ambiguous match degrades to the raw stored value rather than guessing which
resource's configuration applies.

Say the consequence plainly, because "the raw stored value" reads more
conservative than it is: for a *separator-configured* field that value is
the delimited `String`, so this is the one case where the published
"`List<String>` in every case" is false, and a client parsing that field off
a relation row gets a type it was told it would never see. It is not fixable
by splitting anyway — the separator is a property of the resource, which is
exactly what could not be resolved, and splitting on a guessed one publishes
one wrong tag instead of two right ones. Several resources over one model is
an ordinary panel shape (this package's own fixtures put five over
`Company`), so the honest degradation is kept and the consequence documented
here and in `contract/README.md`, rather than traded for a guess.

This mirror is a reproduction of Filament's behaviour, not a general
capability this package now has — a future Filament release changing
`dehydrateStateUsing()` would silently diverge from it. The test that would
catch that asserts the **stored column**, not the response code: a `200`
was never the question this feature had to answer.

### Per-tag rules, and the name-space split this package already established

`TagsInput` implements `HasNestedRecursiveValidationRules` —
`getNestedRecursiveValidationRules()` — and this package had never handled
that interface before this slice; a `->nestedRecursiveRules(['max:20'])` on
the field was silently unenforced by the mobile API, in violation of this
package's own standing rule that the rules a client is shown and the rules
the server enforces cannot drift apart.

The fix reuses the split P6c's repeater already established, for the same
reason: `RuleExtractor` mints **both** `labels` and `labels.*` — the second
for validation, keyed by index, so a `->nestedRecursiveRules(['max:20'])`
violation on the phone's second tag comes back `422` keyed `labels.1`, not a
whole-field error. `WritableNames` — the settle's allow-set — contributes
**only** `labels`: `Write\SettledSchema::reset()` calls `Arr::set()`/
`Arr::has()`, neither of which has wildcard support, so a starred name in
the allow-set cannot be expressed at all, not merely mishandled —
`Arr::has($state, 'labels.*')` is always `false` and `Arr::set` would write a
literal `*` key. Unlike a repeater's per-item names, the starred entry here
is **inert rather than destructive** if it were ever mistakenly admitted:
`labels` is independently in the allow-set and persists on its own, so a
tags field has no analogue to the repeater's row-corruption failure mode —
the split is still mandatory for expressibility, just not for that reason.

**A real bug was found and fixed here: a starred rule name reaching
`/schema` was silently never enforced.** `MobilePanelController`'s
`isRuleNameAllowed()` admitted only the repeater's `name.*.child` shape
(`str_starts_with($name, $allowed . '.*.')`) — which matches
`line_items.*.sku` but never `labels.*`, because a tags field's per-tag rule
has no trailing dot or child segment. So `labels.*` was extracted, published
on `/schema`, and then silently dropped before `$request->validate()` ran —
a 21-character tag under `->nestedRecursiveRules(['max:20'])` saved with a
`200`. The check now also admits `$name === $allowed . '.*'` exactly.

**A tags field whose nested-rule closure throws is refused entirely, not
defaulted.** Every other guarded read in this package degrades a throwing
closure to a safe default and keeps the field usable — but a nested-rule
closure guards a *constraint*, not a hint, so treating a throw as "no nested
rules" would silently widen what the field accepts. `nestedRulesFor()`
returns `null` on a throw, distinct from `[]`, and the caller reads `null` as
"refuse the whole field": no rule, no writable name — the one place on the
tags side where degrading like everything else in this package would make
mobile looser than web.

### Known weaknesses, stated now

- **`splitKeys`, `tagPrefix` and `tagSuffix` are ignored.** A tag commits on
  submit only, and prefixes/suffixes are presentation this slice does not
  reproduce.
- **The separator mirror reproduces Filament's `dehydrateStateUsing()`,
  rather than reading it generically.** A future Filament release changing
  that method's behaviour would silently diverge from this package's copy —
  see "The separator mirror" above for the test that would catch it.

## Key/value

`KeyValue::make('meta')` is a working, editable field on the phone — a
free-form set of string key/value pairs.

```php
KeyValue::make('meta')
    ->keyLabel('Key')
    ->valueLabel('Value');
```

publishes a `keyvalue` node:

```jsonc
{
  "type": "keyvalue",
  "name": "meta",
  "config": {
    "addable": true, "deletable": true,
    "editableKeys": true, "editableValues": true,
    "keyLabel": "Key", "valueLabel": "Value",
    "keyPlaceholder": null, "valuePlaceholder": null
  }
}
```

The value is a `Map<String, String>` on the wire, by construction. The
field's own rule is `array` and nothing narrower — keys and values are
strings by construction, and this package validates neither key uniqueness
nor row count for this type.

**The getters are `canEditKeys()` / `canEditValues()`, not the setter
names.** `KeyValue`'s setters are `editableKeys()` / `editableValues()`; its
getters are `canEditKeys()` / `canEditValues()`, alongside `isAddable()` /
`isDeletable()` — measured against `vendor/`, not guessed. Reading the setter
name through this package's guarded reader would return `null` and fail
open, publishing every field as editable regardless of what the panel
configured. `SchemaWalker::config()` reads the correct four accessors, all
defaulting to `true` to match Filament's own defaults.

**The four gates are client hints, not enforced by the write path — say this
plainly, because it is easy to assume otherwise.** `RuleExtractor` constrains
`meta` to `array` and nothing more: a crafted request can add, remove or
rename a key an `editableKeys: false` gate says it should not be able to,
and the write path persists it verbatim. This matches Filament itself —
the web panel's own dehydration never re-checks these flags either, so
mobile is no looser than web — but it is a real gap from `disabled`, which
this package **does** enforce (`WritableNames` refuses the whole field). It
is left as a hint rather than built out: enforcing it needs the record's
previously-stored keys at validation time to diff against (which keys were
added, removed, or renamed), a different shape of rule than anything else
this package validates, for a field with no reported misuse. An
all-four-gates-`false` `KeyValue` is effectively read-only today and could
join `WritableNames` using the same machinery `disabled` already uses, if a
panel author ever reports relying on the gates as authorization.

### Known weaknesses, stated now

- **No reordering**, matching the repeater — this package's own widget has
  never offered one for either array-valued field.
- **No key-uniqueness validation.** A duplicate key entered on the phone
  collapses in the submitted map, exactly as it does on the web.
- **The four gates are advisory**, per the paragraph above.

## Colour

`ColorPicker::make('accent')` is a working, editable field on the phone.

```php
ColorPicker::make('accent')->rgba(),
```

The node publishes one thing — the **format** the panel declared:

```jsonc
{ "type": "color", "name": "accent", "config": { "format": "rgba" } }
```

`format` is a **closed set** — `hex` (Filament's default), `hsl`, `rgb`,
`rgba`. Anything else normalises to `hex`, because a client cannot act on a
fifth value, and a throwing `format()` closure degrades to `hex` rather than
failing the document.

**The value is never converted.** A field declared `rgb` gets `rgb` back, byte
for byte wherever the user did not edit it. The client parses all four formats
and emits the one it was given; it never offers to switch representation, and
it never "helpfully" normalises `rgb(51, 102, 153)` into `#336699`.

The phone renders a **text field with a live swatch**, not a colour wheel —
this package takes no colour dependency, and a hand-rolled picker's colour
maths is easy to get subtly wrong and hard to test. A malformed value blocks
submission, but **only once the user has edited that field**: the client must
not invent a constraint the server does not have, and it must not block a save
over a value that was already in the database when the form opened.

### Known weaknesses, stated now

- **No graphical picking.** The field is typed, not picked.
- **No format conversion**, deliberately — see above.
- **The `hsl` pattern rejects a fractional hue** while accepting fractional
  saturation and lightness. That is faithful to Filament's own documented
  regex rather than a decision this package made.

## Time and date bounds

`TimePicker::make('opens_at')` is a working, editable field, and **`date` and
`datetime` now publish the bounds they always declared**.

```php
TimePicker::make('opens_at')->minDate('09:00')->maxDate('17:00'),
DateTimePicker::make('published_at')->minDate('2026-01-01')->maxDate('2026-12-31'),
```

```jsonc
{ "type": "time", "name": "opens_at",
  "config": { "minDate": "09:00", "maxDate": "17:00", "seconds": false,
              "minutesStep": 15 } }
```

**`TimePicker` costs almost nothing on the server**, because it is a five-line
class: `extends DateTimePicker`, overriding only `hasDate()`. It inherits every
accessor the date branch already reads, so `time` widens that branch rather
than copying it.

**A bound has two wire shapes**, and both are published verbatim rather than
normalised: `->minDate('09:00')` publishes `"09:00"`, while a Carbon publishes
`"2026-01-01 09:00:00"`. Normalising a bare time into a full datetime would
invent a date the panel never chose, so the client parses both instead.

**Steps publish.** `hoursStep` / `minutesStep` / `secondsStep` appear in
`config` on `datetime` and `time` nodes **only when the evaluated value is
greater than 1** — absent means 1, Filament's default — and never on a `date`
node, which has no time grid. A throwing step closure degrades that one key,
as every closure-backed read does. The keys are advisory, the repeater
`reorderable` precedent: they state what the field was configured with, for a
host rendering its own picker. The server enforces no step, and nothing should
snap a picked value to one — that would make mobile stricter than the panel.

Until this release **no bound reached the client at all** — the walker never
published `config` for a date node, while the Flutter client had parsed
`minDate`/`maxDate` and passed them to its picker since the day it was written.
The code was wired and dead.

### Known weaknesses, stated now

- **Bounds are hints, not rules — final ruling, by web parity.** Filament's
  own web panel does not enforce `minDate`/`maxDate` server-side either (its
  JS picker restricts choice; no validation rule), so a mobile client
  enforcing them would be stricter than the panel it mirrors. The server
  refuses an out-of-range value only if the panel declared a validation rule
  saying so; publishing a bound does not create one.
- **`disabledDates` is not published — finally, not yet-to-build.** It is
  closure-evaluated, which on this contract means schema-generation time, and
  `/schema` is ETag-cached: a dynamic list would freeze at build time and keep
  answering, silently stale, until the panel code changed. A hint that goes
  silently stale is worse than no hint. The day a host asks, the answer is
  per-record evaluation on `/state` — no host has asked.
- **`firstDayOfWeek`, `timezone` and `displayFormat` are not published.** The
  stock Material date picker derives the first day of week from the device
  locale and takes no parameter, so publishing it would state a capability no
  client can honour; timezone and display format stay client-local by the
  standing ruling.

## Relations

![Relation writes](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/relations.png)

A resource's `getRelations()` — the same relation managers a Filament panel
already declares — becomes paginated child lists on mobile, **writable** when
the related model resolves to exactly one registered resource (see Writes
below), and **searchable/sortable** where the host declares it per relation
(see Search and sort below). The manager's own table is never introspected
for any of it: its filters are ignored permanently, and its
`isSearchable()`/`isSortable()` columns are never read — an undeclared
relation is served in relation order. Nothing is declared to opt the list
itself in; every relation manager a resource's `getRelations()` returns is
introspected.

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
`config('filament-mobile.per_page')`. The manager's filters remain the
deliberate non-mirror, above; `?search=`/`?sort=`/`?direction=` are answered
where the host declares them — see Search and sort below.

`/schema` gains a `relations` array per resource — **always present**, `[]`
for a resource with none, never an absent key:

```jsonc
{
  "key": "banners",
  "label": "Banners",
  "card": { "title": { "field": "name" }, "subtitle": { "field": "status" } },
  "recordKey": "id",
  "resource": "banners",
  "search": { "enabled": false },
  "sorts": []
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
- **`resource`** is the child **resource's** key, present only when exactly
  one registered mobile resource owns the related model — zero owners or
  several and the key is absent, and the relation is read-only. One
  resolution drives both the key and the write endpoints' answer, so the
  published schema and a `404` can never disagree about whether a relation
  is writable. Absent means unavailable, the standing rule: a client must
  not invent a write target the server did not declare.
- **`search` / `sorts`** are the same shapes the resource block publishes —
  `{ "enabled": bool }` and a list of `{ "key", "label", "default",
  "direction" }` — and are **always present on a current server**: an
  undeclared relation publishes `search: { "enabled": false }` and `sorts:
  []`. An *absent* key means a server predating P11 — a client reads absent
  `search` as disabled and absent `sorts` as `[]`, never as an error. See
  Search and sort below.

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

### Search and sort — host-declared per relation

```php
MobileResource::make()
    ->relationSearchable('tags', ['name', 'slug'])
    ->relationSorts('tags', ['name' => 'Name', 'created_at' => 'Created'])
    ->relationDefaultSort('tags', 'name');
```

The declarations are **host declarations, keyed by relationship name** —
the same ruling the resource level already made, and for its reasons: the
mobile surface is an explicit opt-in, sorts carry host-worded labels, and
the one table feature that could be introspected (filters) is deliberately
never read. A relation is a smaller list, not a different philosophy, so the
manager's own `isSearchable()`/`isSortable()` columns are never consulted.
Semantics are the resource level's exactly: `relationDefaultSort()` throws
on an undeclared key and normalises/rejects the direction at declaration
time, and a `relationSorts()` call after `relationDefaultSort()` drops a
dangling default. Plain columns only — a dotted path would need a `whereHas`
and is deferred, the index's own ruling, inherited.

A stray declaration — a `relationSorts('tgos', …)` typo naming no relation
the resource declares — is **refused, not stored silently**:
`RelationDiscovery::strayDeclarationKeys()` checks the three declaration
maps exactly as it already checks `relationCard()` keys, and
`filament-mobile:doctor` names each one with the method that declared it.

The endpoint answers the three parameters with the index's exact contract:

- **`?search=`** — LIKE over the declared columns, the same `!`-escaping
  and one-where-group scoping as the index's `applySearch()`. The group
  stays inside the relationship's own constraint.
- **`?sort=` + `?direction=`** — validated against the relation's declared
  sorts; an **unknown key is a `422`**, never a silently ignored parameter.
  A declared default applies when `?sort=` is absent; `?direction=` defaults
  to the default sort's direction when the default key is in play, else
  `asc`.
- **A non-string parameter (`?sort[]=x`) is the same `422`** the index's
  `stringQuery()` promises.
- **Validation runs after the full gate sequence** — resource 404 →
  `viewAny` 403 → relation 404 → record 404 → record `view` 403 → relation
  gate — so a `403`/`404` always wins over a `422`: a validation error must
  never leak whether a relation exists for a record the caller cannot see.
- **Against a relation that declares nothing, search/sort are inert** —
  `enabled: false` means there is nothing to apply — **except an undeclared
  sort key, which still 422s**: the sort parameter claimed a capability, the
  search parameter did not. Filters stay out, permanently.

The machinery is one mechanism, not two: `stringQuery()`, `applySearch()`
and `applySort()` moved verbatim from `MobilePanelController` to
`src/Http/ListQuery.php`, which both controllers now share — `applySort`
takes the resolved `(sorts, defaultKey, defaultDirection)` triple, so the
resource and relation call sites differ only in where the triple comes from.

### Writes — a child row is created, updated and deleted through the parent

```
POST   /api/mobile-panel/{resource}/{record}/relations/{relation}
PUT    /api/mobile-panel/{resource}/{record}/relations/{relation}/{child}
DELETE /api/mobile-panel/{resource}/{record}/relations/{relation}/{child}
```

A relation offers these only where `/schema` published a `resource` key —
one ambiguity answer (`ResourceRegistry::ownersOf()`: zero owners or several
for the related model) drives both, so the schema and the endpoints cannot
disagree. A write against a relation with no single owner, or one this
package does not publish at all, is a **404, not a 403** — the same ruling
the read path makes, for the same reason: a relation this API will never
serve writes for does not exist as far as a client is concerned.

- **The form is the child resource's own**, reused whole, and the write runs
  the identical machinery `store()`/`update()` run — `SettledSchema`, the
  rules as the mass-assignment whitelist, the panel's defaults under the
  payload, the `TagSeparators` mirror, the relation pass — through
  `src/Write/RecordForm.php`, the one home both controllers now share
  (extracted from `MobilePanelController`; nothing about the resource
  endpoints' behaviour changed).
- **The gates are the parent's, then the child's.** Resolution applies every
  gate the read endpoint applies (class `viewAny`, record `view`, the
  relation gate under guard impersonation), then the child model's own
  `create` (class-level — there is no child record yet), `update` or
  `delete` (against the loaded child — authorization, not capability).
- **Create goes through the relationship**
  (`$record->{$relation}()->create(...)`), so the foreign key is the
  parent's by construction — a row is never created floating and checked
  for membership after the fact.
- **`{child}` is the related model's own route key** — the published
  `recordKey` — resolved *through the relationship*: a child id that exists
  but belongs to a different parent is a 404, never a cross-parent write.
- **Status codes are `201` / `200` / `200`.** Delete returns the deleted
  row's serialized form, deliberately not the resource `destroy()`'s 204:
  the relation client holds a *list* it must reconcile, and an empty answer
  would force a re-fetch to learn what it just removed. The row is
  serialized before the delete — afterwards, soft-deleted or gone
  attributes cannot be trusted to read back the same.
- **A validation failure is a `422` keyed by the child's own field names** —
  the shape a top-level `PUT` already returns, so a client renders it with
  no new parsing.
- **Attach and detach are deliberately not exposed.** Pivot operations are a
  different gesture with a different authorization question (which side's
  policy answers `attach`?), and a relation list is not the UI for it. A
  `BelongsToMany` relation here creates and deletes real child rows.

`filament-mobile:doctor`'s Relations section names a **published** relation
whose writes are off, distinguishing the two causes — no registered resource
owns the child model, or several do — because the fixes differ (opt one in;
the model is genuinely ambiguous). The relation reads fine either way, so
this is reported informationally, not as a refusal.

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
- **The relation manager's own filters stay ignored, and its table's
  search/sort columns are never read.** Search and sort exist only where the
  host declares them per relation (Search and sort above); undeclared, the
  list is in relation order, unfiltered. Filters are out permanently — the
  resource level's `'filters' => []` ruling, inherited. A panel whose
  relation manager is only usable with its filters gets a list that is
  technically correct and practically wrong.
- **Only the first two columns become a card.** A relation whose meaning
  lives in its third column looks empty of information.
- **Attach and detach are not exposed.** Create, update and delete through
  the relationship are; pivot operations are not — see the Writes section
  above. A relation whose child model has no single owning resource stays
  read-only, and `doctor` says which of the two causes applies.

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
- **The conversion is memoised per request**, keyed by the raw string on the
  `RecordSerializer` instance — one serializer per request, so the memo's
  lifetime is the request's. Nulls are memoised too: a value whose conversion
  degrades does not pay for its failure twice. The `RelationDiscovery::for()`
  half of the caching pass this bullet used to promise was measured and
  deliberately **not** done — the split already runs exactly once per
  resource per request at every HTTP entry point, so there was no redundancy
  left to remove.

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
drift between `mobile()` and `table()`, card paths that resolve to nothing,
relations it refuses (with the reason), stray per-relation declarations (a
`relationCard()`/`relationSorts()` key naming no relation the resource
declares), and published relations whose rows
are read-only because no single resource owns the child model —
distinguishing zero owners from several, because the fixes differ.
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
  §10.) `FileUpload` now walks as an editable field, single or
  `->multiple()` — see the Upload section above. `Repeater` now walks as an editable field too — see the Repeater
  section above; `Repeater::relationship()` writes through Filament's own
  relation pass, at a delete-all-then-recreate cost per save.
  `RichEditor` now walks too — a `textarea` on the form always (see Rich
  text above), and `rich_entry` on the infolist where `->prose()` or the
  model's own `HasRichContent` says the column is rich. `Radio`, `TagsInput`
  and `KeyValue` now walk as editable fields too — see the Radio, Tags and
  Key/value sections above. So do `ToggleButtons` and `Slider` — see the
  Toggle buttons and Slider sections above. None of the percentages above has
  been re-measured against the pilot panel since these shipped.
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
- **`filters` is always `[]`.** Table filters are not introspected — at the
  resource level, and per relation (which inherits the ruling permanently;
  relation search and sort are host declarations instead — see Relations).
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
