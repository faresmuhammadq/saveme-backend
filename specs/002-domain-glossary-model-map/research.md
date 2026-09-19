# Research: Domain Glossary & Model Map

**Feature**: 002-domain-glossary-model-map | **Date**: 2026-09-20

Decision / Rationale / Alternatives for every judgement the glossary needs. The entity table these feed is in [data-model.md](./data-model.md).

---

## R-1: Where the glossary lives

- **Decision**: `docs/glossary.md`, a single file beside `docs/adr/`. `docs/legacy/DATA_MODEL.md` gains a pointer to it.
- **Rationale**: readers include the Android and landing-page repositories, who have no reason to browse `specs/`. A spec folder records one moment; the glossary is amended continuously as entities are added.
- **Alternatives**: inside `specs/002-…/` (invisible to other teams, and would need copying forward); split into one file per context (six files to keep consistent, and the banned-synonym table has no natural home); in the constitution (the constitution states rules, not inventories).

## R-2: FR-019 — how a member sees another member's category

The problem created by user-scoped categories: my partner's categories ride *their* user feed, so my device never receives them, yet their transactions in our shared wallet reference them. My device would hold a category id it cannot render.

- **Decision**: a **Category Reference** — a read-only projection carried on the **wallet** feed. When a transaction in a wallet references a category, the server publishes that category's display fields (id, name, icon, colour, kind, owner user id) into the wallet's change feed. It is server-produced, never accepted from a client, and never editable by anyone but the category's owner (through their own Category record). Updating a category republishes the projection to every wallet whose transactions reference it.
- **Rationale**: the client renders any transaction in a wallet it can see, with no extra round trip and no offline gap. Ownership stays clean — one writer, many readers — and it does not widen anyone's edit rights, so P-08 is untouched. It reuses the wallet feed that already exists rather than inventing a third scope.
- **Alternatives**:
  - *Denormalise the category name onto the transaction*: simplest, but the name is frozen at write time, so a rename never propagates and the same category shows two names in one list. **Rejected.**
  - *Copy categories between members on share*: creates duplicate entities with divergent edits and makes "whose category is this" unanswerable. **Rejected.**
  - *Fetch foreign categories on demand over HTTP*: breaks offline rendering, which is a core product property. **Rejected.**
  - *Make categories wallet-scoped after all*: reverses a settled decision; also forces a user with three wallets to maintain three category lists. **Rejected** (settled 2026-09-20).
- **Consequence for later specs**: Wallets & Ledger owns maintaining the projection; Sync must treat it as read-only on push; A2 must document it as a resource that appears in a feed but has no write endpoint.

## R-3: Owning context where two modules touch one entity

The constitution demands exactly one owner per entity (P-02, FR-004). Three entities are genuinely contested:

| Entity | **Owner** | Consumer | Why |
|---|---|---|---|
| Wallet Member | **Wallets & Ledger** | Sharing writes it on invitation acceptance, through `WalletService` | Every authorization check reads it, and authorization is wallet-scoped (ADR-0006). Putting it in Sharing would make Wallets depend on Sharing for its own access control |
| Category | **Wallets & Ledger** | Budgeting reads it; Reporting groups by it | It is the ledger's posting counter-leg (ADR-0012), so the ledger cannot be correct without it. Budgeting referencing it is an ordinary cross-module read |
| Invitation | **Sharing** | Wallets reads nothing from it; acceptance calls into Wallets | Its whole lifecycle (send, accept, decline, revoke, expire) is Sharing's business. The resulting membership is Wallets' |

- **Alternatives**: a "Membership" module owning both Wallet Member and Invitation (a tenth context, needing a constitution amendment for no gain); Sharing owning Wallet Member (inverts the authorization dependency).

## R-4: Occurrence — materialised or computed

- **Decision**: **materialised**. A Recurring Rule generates Occurrence records ahead of time (a rolling horizon, length fixed by the Recurring spec). Confirming an occurrence produces a Transaction and links back to it.
- **Rationale**: the client must show and confirm upcoming items offline, which is impossible if occurrences exist only as a server-side computation. Gam'eya and installment plans also need per-occurrence state ("paid", "skipped") that has nowhere to live in a computed model.
- **Alternatives**: compute on read (no offline confirmation, no per-occurrence state); materialise only on confirmation (the list the user acts on would not exist until acted on).

## R-5: The "Period" collision

- **Decision**: the bare word "Period" is **not an entity name**. It splits into:
  - **Budget Period** — a named calendar window (month in MVP) owned by Budgeting, **derived** from the budget's period type plus a date; not persisted in MVP.
  - **Billing Period** — the `current_period_start` / `current_period_end` fields on Subscription, owned by Plans & Billing. Not an entity.
- **Rationale**: one word naming a budget month and a subscription cycle is exactly the ambiguity this document exists to remove.
- **Alternatives**: keep "Period" as a Budgeting entity and call the billing one "cycle" (still two similar words, and the collision resurfaces in report code); persist budget periods now (needed only for rollover, which is deferred).

## R-6: Sync Cursor is not a server entity

- **Decision**: **Sync Cursor is a value held by the client**, not a persisted server record. The server side of the mechanism is the `change_seq` counter on the scope row (wallet or user) plus the `change_seq` stamped on each syncable entity (ADR-0008). The glossary keeps a Sync Cursor entry, marked "not persisted server-side", because specs and the client talk about it constantly.
- **Rationale**: ADR-0008 already defines the mechanism, and it has no server-side row. Listing it as a stored entity would invite somebody to create a `sync_cursors` table that duplicates state the client owns, then to disagree with it.
- **Alternatives**: persist a per-device cursor server-side (a second source of truth that drifts, and a write on every pull); omit it from the glossary (it is a named concept in the protocol; omitting it is how synonyms start).

## R-7: Derived versus persisted

- **Decision**: **Entitlement**, **Report** and **Budget Period** are derived and have no table. **Plan Feature** is data inside a Plan's feature map, not a table, but keeps an entry because specs name it.
- **Rationale**: P-09 requires capability checks to go through the entitlement service; a persisted entitlement row would become a cache of plan data that can contradict the subscription. Reports are queries by definition.
- **Alternatives**: materialise entitlements per user for speed (premature; the service can cache per request, per ADR-0007); make Plan Feature a table (adds a join for data that is always read with its plan).

## R-8: Device Session

- **Decision**: **Device Session** is the Identity-owned record behind one device's access token: device name, platform, last-seen, created-at, revoked-at. It is **user-scoped syncable**, so revoking a session on one device propagates to the others. The token itself is never an entity in the glossary and never leaves the auth layer.
- **Rationale**: the user-facing concept is "the devices signed in to my account", which is what a revocation list must show. Syncing it makes the security surface visible on every device the user owns.
- **Alternatives**: not syncable, fetched on demand (a revoked device would keep believing it is signed in until its next call — which is arguably fine, but the *other* devices would not see the change offline); calling it "Token" (leaks the auth mechanism into product vocabulary, and A4 may change the mechanism).

## R-9: Category lifecycle and deletion

- **Decision**: **active → archived**, and nothing else. A category that any posting references MUST NOT be hard-deleted; archiving hides it from pickers while leaving history intact. Deleting a never-used category is permitted and is an ordinary tombstone.
- **Rationale**: ADR-0012 made categories a posting account, so a cascade would destroy ledger rows and a re-point would silently rewrite history the user did not authorise.
- **Alternatives**: re-point to an "Uncategorised" category on delete (rewrites the user's records without asking); block deletion outright with no archive (leaves dead categories in every picker forever).

## R-10: Arabic terms — what each one names

- **Decision**:
  | Term | English | Kind |
  |---|---|---|
  | محفظة | Wallet | **entity** |
  | حصالة | savings wallet | **type value** — `wallets.type = savings` |
  | جمعية | rotating savings group (gam'eya) | **preset** of Recurring Rule |
  | قسط | installment | **preset** of Recurring Rule |
- **Rationale**: only محفظة names an entity. Recording the other three as a type value and two presets stops someone creating `Gameya` and `Installment` tables, which would fragment the recurring engine.
- **Alternatives**: treat جمعية as its own entity (it has members, a rotation order and a payout schedule — real modelling, but it belongs to the Recurring spec and can be an extension of the rule rather than a parallel entity); leave Arabic to the translation files (then engineers invent English names for concepts that have none, e.g. "money circle").

## R-11: Prior-art mapping (the eight MVP entities)

| `DATA_MODEL.md` | Glossary | Note |
|---|---|---|
| User | User (+ Profile, + Device Session) | Split: credentials stay on User, display/locale/timezone move to Profile |
| Space | **dropped** | Ownership is the wallet; sharing is Wallet Member (ADR-0006) |
| SpaceMember | **dropped** → Wallet Member | Roles become owner/editor/viewer |
| Wallet | Wallet | `cached_balance` → `balance_minor`; `space_id` → owner user + members |
| Category | Category (+ Category Reference) | Now user-scoped; projection added for shared wallets (R-2) |
| Transaction | Transaction | One category in MVP; `space_id` dropped |
| Posting | Posting | Wallet XOR category, per ADR-0012 |
| Budget | Budget | User-scoped and personal; Budget Period derived |

Deferred prior-art entities (`BalanceAssertion`, `ImportBatch`) are listed in the glossary's "deferred, not built" section and mapped to no MVP entity.

## R-12: Field conventions to fix once

- **Decision**: the glossary fixes six recurring shapes so no feature re-invents them: identifier (`id`, UUIDv7), money (`<name>_minor` integer + `currency`), calendar date (`date`, `YYYY-MM-DD`), instant (UTC, microsecond), optimistic concurrency (`version`), sync ordering (`change_seq`), and the soft-delete marker (`deleted_at`). See [contracts/naming-rules.md](./contracts/naming-rules.md).
- **Rationale**: these are the fields that appear on nearly every entity; one table prevents `amount_cents` here and `total_minor` there.
- **Alternatives**: leave them to each feature spec (which is how the two names above happen); restate them in the constitution (it already states the rules; this is the naming, which belongs with the vocabulary).

## R-13: What the glossary deliberately does not contain

- **Decision**: no column types, indexes, endpoints, payloads or permission matrix. Each entry names *what* exists and *who owns it*, not how it is stored or exposed.
- **Rationale**: FR-016/FR-017. Duplicating the permission matrix here would create the second copy the constitution forbids (P-08 rule 5); duplicating schema would rot the moment a migration lands.
- **Alternatives**: a fuller "model map" with column lists (becomes a stale second schema within one feature); linking to each feature spec's data model (fine, and the glossary does link — it just does not copy).
