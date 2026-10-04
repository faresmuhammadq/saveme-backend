# Feature briefs

The Phase B feature briefs, plus the admin dashboard foundation, recorded 2026-09-25. Each file holds one brief, verbatim, followed by the decisions it collides with.

**To start a feature**, run `/speckit-specify <ID>` — for example `/speckit-specify B6`. The brief's Description is the input; its Considerations carry into `/speckit-plan` and `/speckit-clarify`; its **Reconcile at specify time** table lists what must be settled before the spec is written.

## Order

Recommended order, with the dependencies that force it. A spec directory takes the next free number when it is created, so directory numbers will not match brief numbers.

| # | ID | Brief | Needs first | Notes |
|---|---|---|---|---|
| 1 | [B1](B01-identity-auth.md) | Identity & authentication | — | Specified as spec 007; Sanctum approved |
| 2 | [B2](B02-profile-preferences.md) | Profile & preferences | B1 | |
| 3 | [B3](B03-plans-entitlements.md) | Plans & entitlements | B1 | Built as spec 009; QA passed 2026-09-28. Free is the absence of a subscription (ADR-0013) |
| 4 | [D1](D01-admin-dashboard-foundation.md) | Admin dashboard foundation | — | Specified as spec 006 against the Calm Clarity design source; UI only, sign-in split to D2 |
| 4a | [D2](D02-operator-sign-in.md) | Operator sign-in | D1 | Must land before B11 and B12; one-time-code library needs approval, or in-house RFC 6238 |
| 5 | [B4](B04-wallets-membership.md) | Wallets & membership | B1, B3 | Balance in the same transaction (decided) |
| 6 | [B5](B05-categories.md) | Categories | B4 | User-scoped plus platform-owned (decided); updates glossary and ADR-0012 |
| 7 | [B6](B06-transactions-ledger.md) | Transactions ledger | B4, B5 | |
| 8 | [B14](B14-sync-protocol.md) | Sync protocol | B6 | Contract right after B6. Batch upload supersedes ADR-0008 rule 7 |
| 9 | [B7](B07-recurring-transactions.md) | Recurring transactions | B6 | |
| 10 | [B8](B08-budgets.md) | Budgets | B5, B6 | Answers six banked Budgeting questions |
| 11 | [B9](B09-sharing-invitations.md) | Sharing & invitations | B3, B4 | Specified as spec 016 |
| 12 | [B10](B10-billing-google-play.md) | Billing — Google Play | B3 | Google SDK needs approval |
| 13 | [B11](B11-billing-instapay.md) | Billing — InstaPay claims | B3, D1, D2 | Specified as spec 020 |
| 14 | [B12](B12-back-office-operations.md) | Back-office operations | D1, D2, B3, B11 | Filament replaced by Livewire (P-18) |
| 15 | [B13](B13-reports.md) | Reports | B6, B8 | Specified as spec 019 |
| 16 | [B15](B15-export-data-deletion.md) | Export & data deletion | B6, B9 | Ownership transfer (decided); amends the security baseline |
| 17 | [B16](B16-devices-push.md) | Devices & push notifications | B1, B7, B9 | Firebase SDK needs approval |

## The brief set's stack line, reconciled

The briefs were written against an earlier stack. What is actually in force:

| Brief says | In force | Where |
|---|---|---|
| Laravel 12, PHP 8.3+ | Laravel 13, **PHP 8.5** | `composer.json`, spec 005 |
| MySQL 8 or PostgreSQL 16 | **PostgreSQL 16** only | ADR-0001, P-16 |
| Laravel Sanctum | **Installed** — `laravel/sanctum` decided in B1, spec 007 (approved 2026-09-26) | security baseline, spec 007 |
| Filament for admin | **Livewire and Blade** | P-18 |
| `{ "amount": 12500 }` | `amount_minor` + `currency` | ADR-0003, spec 003 |
| `Idempotency-Key` on all money POSTs | Only the three non-upsert operations | spec 003 |
| Per-wallet sync, 30-day tombstones | Two scopes, 90-day tombstones, no server merge | ADR-0008, P-07 |

## Collisions that need an amendment or a superseding record

Four collisions needed an owner decision. All four are decided:

1. **B4 / B6 — balance recomputed by a queued job.** ✅ Decided: recomputed **in the same database transaction**, as P-05 requires. Nothing to amend.
2. **B14 — server-side last-write-wins and a batch push.** ✅ Decided: **the phone resolves clashes, the server never merges** — P-07 stands. **Upload is a batch** with a result per item; each item obeys the single-write rules. Needs a decision record superseding ADR-0008 rule 7, written in B14.
3. **B5 — categories in a wallet.** ✅ Decided: **user-scoped plus a platform-owned default set**. The glossary and ADR-0012 change in B5. B8 budgets stay user-scoped as the glossary fixes.
4. **B15 — owned shared wallets on account deletion.** ✅ Decided: the departing user's transactions stay with their reference; ownership transfers — to the only other member, or to one the departing user chooses, falling back to the oldest editor. Amends the security baseline and the privacy commitments in B15.
