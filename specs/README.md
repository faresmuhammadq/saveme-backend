# Specs backlog

What is written, what is next, and what each remaining spec must decide. One folder per spec under `specs/`, created by `/speckit-specify`.

**Governing documents**: [constitution v1.1.0](../.specify/memory/constitution.md) · [ADR index](../docs/adr/README.md) · prior art: [docs/legacy/DATA_MODEL.md](../docs/legacy/DATA_MODEL.md)

## Status

| ID | Spec | Folder | State |
|---|---|---|---|
| A1 | Architecture constitution & ADR set | [`001-architecture-constitution-adrs`](001-architecture-constitution-adrs/) | ✅ Done — constitution v1.1.0, ADRs 0001–0012 |
| A3 | Domain glossary & model map | [`002-domain-glossary-model-map`](002-domain-glossary-model-map/) | ✅ Done — [docs/glossary.md](../docs/glossary.md), 22 entities |
| A2 | API conventions | → `003-…` | ⏭️ **Next** — inherits from A3: the scope key is not always `wallet_id` (4 user-scoped entities), `Category Reference` is a read-only feed resource with no write endpoint, and a Transaction carries exactly one category in MVP |
| A4 | Security & privacy baseline | → `004-…` | Queued |
| A5 | Engineering conventions | → `005-…` | Queued |

**Follow-ups from A1** (small, not full specs): F-1 architecture tests (P-02, P-03, P-09) · ~~F-2 PostgreSQL for dev/test/CI~~ ✅ done 2026-09-19 · F-3 observability middleware + JSON logs + error tracker (needs dependency approval) · F-4 shared kernel (`Money`, `Currency`, clock).

**Then the MVP feature areas** (from the brief, no specs yet): identity & auth · profile & preferences · plans & entitlements · wallets & membership · categories · transactions ledger · recurring transactions (incl. gam'eya and installments) · budgets · sharing & invitations · billing (Play + InstaPay) · admin back-office · reports · offline sync · export & account deletion · devices & push.

### Why A3 before A2

A2 fixes an envelope in which every syncable resource exposes `wallet_id`. Whether that holds depends on where categories and budgets live once `Space` is dropped — an A3 question. Writing A2 first would freeze a contract around entities whose scope is undecided. A4 and A5 are independent of both and can be pulled forward.

---

## A3 · Domain glossary & model map → `002-…`

**Description.** The ubiquitous language and the entity index: User, Device Session, Profile, Plan, Plan Feature, Subscription, Entitlement, Payment Claim, Redeem Code, Wallet, Wallet Member, Category, Transaction, Posting, Recurring Rule, Occurrence, Budget, Period, Invitation, Sync Cursor, Report. For each: owning context, aggregate root, lifecycle states, which other entities may reference it, and whether it is syncable. Arabic terms beside English where the UI uses them (محفظة, حصالة, جمعية, قسط).

**Considerations.** This supersedes the entity list in DATA_MODEL.md as the single index; the eight existing entities must map 1:1 onto it or the difference is documented. Fix naming here so the coding agent never invents synonyms (account vs wallet, member vs collaborator).

**Settled input** — decided 2026-09-20 while reconciling [docs/legacy/DATA_MODEL.md](../docs/legacy/DATA_MODEL.md); do not re-ask:

| # | Question | Decision |
|---|---|---|
| 1 | `Space` / `SpaceMember` vs wallet-scoped membership | **Drop `Space`.** Membership lives on the wallet, roles `owner\|editor\|viewer` (ADR-0006). A3 re-homes what `Space` owned |
| 2 | Posting counter-leg: nominal `income`/`expense` vs category | **Category.** A posting references `wallet_id` XOR `category_id` — [ADR-0012](../docs/adr/0012-postings-wallet-and-category-accounts.md), superseding ADR-0004 |
| 3 | Money representation | **Integer minor units only**; no `NUMERIC(19,4)` (ADR-0003) |
| 4 | Syncability fields | **`version`, `change_seq`, `deleted_at`** on every syncable entity (ADR-0008) |
| 5 | Balance column naming | **`balance_minor`, `opening_balance_minor`** (ADR-0005) |
| 6 | User timezone | **`users.timezone`**, IANA name (ADR-0011) |
| 7 | `BalanceAssertion`, `ImportBatch` | **Deferred** — statement import is out of MVP scope (P-17) |
| 8 | O1 postings vs two-column | **Postings** — closed by ADR-0012 |
| 9 | Prior-art extras to keep | Wallet `type` enum (`cash\|checking\|savings\|credit_card\|loan\|investment`), `on_budget`, `archived`, `cleared`, currency immutable after first posting, `kind = transfer` excluded from spending and income reports |

**Resolved during `/speckit-specify` on 2026-09-20** (see [spec.md → Clarifications](002-domain-glossary-model-map/spec.md)):

- **Categories and budgets are user-scoped** — one personal set per user, reused across their wallets, on the user sync feed. A budget limits its owner's own spending, not a shared household total.
- **No split transactions in MVP** — one category per transaction. The ledger still permits several category legs, so splits are a later contract change, not a migration.
- **Category nesting deferred** — the field is reserved, the behaviour is out of scope.
- **Categories are archive-only** — active → archived; a category any posting references is never hard-deleted.

Left for the glossary itself: since categories ride the user feed, a member of a shared wallet cannot see their partner's category list, yet their partner's transactions reference it. Recommended fix is a read-only projection of referenced categories on the wallet feed (FR-019).

---

## A2 · API conventions → `003-…`

**Description.** Define the HTTP contract shape for all endpoints: base path /api/v1; JSON only; success envelope { "data": …, "meta": … }; errors follow RFC 9457 (type, title, status, detail, code, errors[] for validation) with a versioned error-code catalog; cursor pagination (meta.next_cursor); filtering and sorting grammar; Accept-Language (ar, en) drives message locale; Idempotency-Key header required on all POSTs that create money-affecting records, stored 24h; rate-limit headers; X-Client-Version and minimum-version gating; deprecation header policy. The syncable-entity contract: every syncable resource exposes id (UUIDv7), wallet_id (where applicable), version (int, server-incremented), created_at, updated_at, deleted_at; writes must send version and receive 409 VERSION_CONFLICT with the current state when stale.

**Considerations.** The KMP client generates IDs, so create endpoints are PUT /…/{id} (idempotent upsert) rather than POST. Amounts are always { "amount": 12500, "currency": "EGP" }, never a decimal string. Dates that are calendar dates (transaction date) are YYYY-MM-DD, not timestamps. Validation errors must carry field paths the client can map to inputs. Produce the OpenAPI skeleton (info, servers, security schemes, shared schemas, error responses) that every feature spec extends.

**Conflicts to resolve** (these become the clarification questions):

| Description says | Already ratified | Note |
|---|---|---|
| `{"amount": 12500, "currency": "EGP"}` | ADR-0003 fixed `amount_minor` | Recommend keeping `amount_minor`: `amount: 12500` reads like 12,500 EGP to a newcomer, the exact confusion ADR-0003 exists to prevent |
| RFC 9457 problem+json | [`001/contracts/http-conventions.md`](001-architecture-constitution-adrs/contracts/http-conventions.md) defines a custom `{"error": {...}}` envelope | RFC 9457 is the better standard; adopting it means deciding where the 409's `current` entity sits (a problem extension member) and rewriting that contract |
| `Idempotency-Key` required on money-affecting POSTs | Creates are `PUT /{id}` upserts, already idempotent (ADR-0002) | Narrow to the few non-syncable POSTs: InstaPay claim, redeem code, Play purchase ack |
| Syncable fields omit `change_seq`; assume `wallet_id` | ADR-0008 exposes `change_seq` and defines **two** scopes, wallet and user | Add `change_seq`; generalise the scope key (profile, devices and preferences are user-scoped) |

---

## A4 · Security & privacy baseline → `004-…`

**Description.** Sanctum personal access tokens, one per device session, 90-day sliding expiry, revocable; password hashing argon2id; login and reset endpoints rate-limited per IP and per account with lockout after N failures; email verification required before any wallet write; PII (email, display name) encrypted at rest with Laravel's encrypted casts; audit log of security events (login, token revoke, password change, role change, admin actions); webhook signature verification (Google Pub/Sub JWT); data retention: soft-deleted records purged after 30 days, deleted accounts purged after 30-day grace; admin access via separate guard with 2FA.

**Considerations.** The app is a personal-finance product in a market where the local competitors are criticised for "data not encrypted" — this baseline is a marketing asset, so write the user-facing privacy commitments alongside the technical ones. Do not log request bodies containing amounts or notes.

**Conflicts / prerequisites:**

| Point | Repo reality | Note |
|---|---|---|
| Sanctum | **not installed** (`composer.json` has no `laravel/sanctum`) | New dependency → needs owner approval at spec time |
| Admin 2FA | ADR-0006 already fixes a separate `admin` guard with its own accounts | Consistent; 2FA is the addition |
| Soft-deletes purged after 30 days | ADR-0008 retains tombstones **≥ 90 days** for sync | **Direct conflict.** A client offline 60 days would lose data silently. Reconcile: 90-day sync tombstones vs 30-day purge of other soft-deletes |
| "Do not log amounts or notes" | ADR-0010 already bans full financial payloads and names the never-log list | Consistent; A4 extends it |
| PII encrypted at rest | — | Encrypted casts make `email` non-searchable by equality unless a blind index is added; decide at spec time |

---

## A5 · Engineering conventions → `005-…`

**Description.** Repo layout per module; the spec template (purpose · glossary · data model · invariants · API · authorization & entitlements · events · acceptance scenarios · open questions); OpenAPI is the source of truth and contract tests are generated from it; Pest with three layers (domain unit, application feature, HTTP contract); factories and seeders per module; migrations are append-only after first release; .env profiles for local/ci/staging/prod; GitLab CI stages lint → static analysis (PHPStan level 8, Pint) → test → build image; Docker Compose for local DB/Redis/Mailpit; Conventional Commits; API semver.

**Considerations.** Tell the coding agent explicitly that every feature PR must ship: migration, OpenAPI fragment, policy, tests at all three layers, and a seeder entry if the feature has reference data.

**Conflicts to resolve:**

| Description says | Repo reality | Note |
|---|---|---|
| PHPStan **level 8** | `phpstan.neon` is level 7, and constitution P-16 rule 1 fixes level 7 | Raising it requires a constitution amendment (PATCH or MINOR), not just a config edit |
| **GitLab CI** stages | GitHub Actions (`.github/workflows/tests.yml`); no GitLab, no remote configured yet | Pick one before writing the pipeline |
| **Docker Compose** for local DB/Redis/Mailpit | The `dev` CLI + shared stack (`postgres-16`, `redis-7`, `mailpit`), set up in F-2 | Record the `dev` workflow as the convention instead |
| Repo layout per module | Constitution already fixes `app/Modules/<Context>/{Domain,Application,Infrastructure,Http}` (P-02) | A5 restates, never contradicts |
| Migrations append-only after first release | Nothing ratified | Compatible; adopt as written |

---

## Open questions banked for later feature specs

Found while validating the glossary (spec 002) with a fresh-context reader. None block A2–A5; each belongs to the feature named.

**Budgeting**

- How is a transaction in a **shared** wallet attributed to one member's budget — by the owning user of the category it references, or by the transaction's `created_by`? These diverge as soon as one partner recategorises the other's transaction.
- What currency is a budget denominated in when its owner holds wallets in more than one? Postings balance per currency and FX is out of scope, so aggregating "spending across their wallets" is undefined.
- May an `income` category carry a budget? Is there one budget per (user, category, period), or may they overlap?
- What happens to a live budget when its category is archived — dormant, archived with it, or still accumulating?
- Which module serves **budget progress** (Budgeting read model or a Reporting report)? That decides which module exposes the endpoint.
- Are budget-overrun notifications in scope? Nothing mentions them.

**Wallets & Ledger**

- The exact trigger that withdraws a `Category Reference` from a wallet feed when nothing references it any more.
- The interface and shape of the query services Budgeting and Reporting call (per category, per period, per currency?). Only `WalletService` and `EntitlementService` are named so far.

**Constitution (next amendment, cosmetic)**

- Its context table gives Notifications "devices, push tokens", while the glossary puts **Device Session** (auth) under Identity and leaves Notifications the push registration. Both documents are internally consistent; the word "device" reads differently across them.

## Conventions for this backlog

- A spec moves from here into `specs/NNN-<short-name>/` via `/speckit-specify`, then `/speckit-plan`, `/speckit-tasks`, `/speckit-implement`.
- Decisions listed as **settled input** are not re-asked during clarification; conflicts listed are.
- A decision that contradicts an Accepted ADR is resolved by a **new ADR that supersedes it** — never by editing the old one (see the constitution's Governance section).
