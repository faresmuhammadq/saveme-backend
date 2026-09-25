# B2 · User profile & preferences

**Call with**: `/speckit-specify B2` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A user reads and updates display name, locale (`ar`/`en`), default currency (ISO code, default EGP), budget period start day (1–28, "salary day"), timezone (IANA), and notification preferences placeholder. `GET/PATCH /me`.

## Considerations

Period start day changes affect budgets and reports going forward only; state that existing periods are not re-cut. Currency is per wallet from spec 4 onward — the profile value is only the default for new wallets. Locale also selects the seeded category names (spec 5). Validate timezone against the IANA list.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| `PATCH /me` | Profile is a user-scoped **syncable** entity (glossary); syncable writes are `PUT` upserts carrying `version` (spec 003) | Decide whether Profile is written through the upsert contract or is the one documented exception |
| Display name | Encrypted at rest (security baseline) | Encrypt; no lookup by name |
