# Changelog

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
