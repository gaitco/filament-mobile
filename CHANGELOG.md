# Changelog

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
