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
| `POST /api/mobile-panel/{resource}` | Create, validated from the resource's own schema |
| `PUT /api/mobile-panel/{resource}/{record}` | Update, same validation |
| `DELETE /api/mobile-panel/{resource}/{record}` | Delete, gated on the record's own policy |
| `POST /api/mobile-panel/{resource}/state` | Re-evaluate the schema against submitted values, for reactive forms |

Every write is authorised through the panel's own policies, and its validation
rules are extracted from the same schema the read path publishes — so the rules
a client is shown and the rules the server enforces cannot drift apart.

![How it works](https://raw.githubusercontent.com/gaitco/filament-mobile/main/art/diagram.png)

---

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

## Installation

```bash
composer require gait/filament-mobile
php artisan vendor:publish --tag=filament-mobile-config
```

Then apply the two items above, and opt at least one resource in.

## Opting a resource in

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
];
```

`resources` is an explicit opt-out of panel discovery — useful where no panel is
booted, or to serve a deliberate subset.

## Known limitations in this release

Measured against a real 35-resource production panel.

- **91.7% of components walk cleanly.** The rest emit a warning and are omitted
  — never silently dropped. `RichEditor`, `Repeater`, file uploads and
  `Schemas\Components\Livewire` are out of scope. (86.4% was the rate *before*
  `CheckboxList` was mapped; see the pilot's §10.)
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
- **A multi-valued relationship field can never be written from mobile.** The
  write path is `Model::create()`/`$record->update()` on the validated array; it
  never calls Filament's `saveRelationships()`. So a `Select::multiple()
  ->relationship()` or a `CheckboxList->relationship()` returns `201`/`200`
  having attached nothing, and — because Filament resolves those fields'
  dehydration through a closure, which is indistinguishable through the public
  API from a legitimate `dehydrated(fn ($state) => filled($state))` — the field
  is still published as `disabled: false`. A client will render it editable. A
  single-valued `Select::relationship()` is fine: it writes a foreign key like
  any other column. Relation writes are P3.
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
