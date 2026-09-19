# Feature Specification: Domain Glossary & Model Map

**Feature Branch**: `002-domain-glossary-model-map`

**Created**: 2026-09-20

**Status**: Draft

**Input**: Backlog item A3 (see [specs/README.md](../README.md)): "The ubiquitous language and the entity index: User, Device Session, Profile, Plan, Plan Feature, Subscription, Entitlement, Payment Claim, Redeem Code, Wallet, Wallet Member, Category, Transaction, Posting, Recurring Rule, Occurrence, Budget, Period, Invitation, Sync Cursor, Report. For each: owning context, aggregate root, lifecycle states, which other entities may reference it, and whether it is syncable. Arabic terms beside English where the UI uses them (محفظة, حصالة, جمعية, قسط). Considerations: This supersedes the entity list in DATA_MODEL.md as the single index; the eight existing entities must map 1:1 onto it or the difference is documented. Fix naming here so the coding agent never invents synonyms (account vs wallet, member vs collaborator)."

> **Nature of this feature.** The deliverable is a reference document, not runtime behaviour. Its readers are the people and agents who spec, build, review and translate every later feature, plus the Android and landing-page teams who must use the same words. Domain vocabulary is the subject matter here, not leaked implementation detail.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Builder looks up an entity before writing anything (Priority: P1)

Someone about to spec or build a feature (person or agent) opens the glossary, finds the entity they are about to touch, and learns in one place: which module owns it, whether it is an aggregate root or lives under one, what states it moves through, what may point at it, and whether it syncs to the phone. They start work without inventing a name or a field.

**Why this priority**: Every later spec depends on this. Without it, each feature re-invents entity names and scopes, and the divergence is only discovered in review — or worse, in the client.

**Independent Test**: Hand a builder a feature brief ("budgets per category per period") and only the glossary. They can name every entity they will touch, say which module owns each, and say which of them sync — with no further questions.

**Acceptance Scenarios**:

1. **Given** the glossary, **When** a builder looks up Transaction, **Then** they find it owned by Wallets & Ledger, an aggregate root, syncable, with Posting listed as a child that is never synced or exposed.
2. **Given** the glossary, **When** a builder looks up Entitlement, **Then** they find it is derived rather than stored, owned by Plans & Billing, and reachable only through the entitlement service.
3. **Given** a feature brief mentioning "collaborator", **When** the builder checks the glossary, **Then** they find the banned-synonym list and use Wallet Member instead.

---

### User Story 2 - Reviewer rejects vocabulary drift with a citation (Priority: P1)

A reviewer sees a pull request introducing `account_id`, a `collaborators` table, or a "Group" entity. They cite the glossary's canonical term and banned-synonym list rather than arguing from taste.

**Why this priority**: The constitution's P-01 forbids synonyms for Wallet but says nothing about the other twenty entities. Without a list, the rule is unenforceable everywhere else.

**Independent Test**: Seed six renamed entities and field names; a reviewer maps each to the canonical term using only the glossary.

**Acceptance Scenarios**:

1. **Given** a migration creating `collaborators`, **When** reviewed, **Then** the reviewer cites Wallet Member as the canonical term.
2. **Given** code calling a money container an "account", **When** reviewed, **Then** the reviewer cites P-01 and the glossary's banned list.

---

### User Story 3 - Client and translator share one vocabulary (Priority: P2)

The Android team and whoever writes the Arabic strings use the same names as the backend. A term the UI shows in Arabic (محفظة, حصالة, جمعية, قسط) is recorded next to its English entity, so translation is a lookup instead of a judgement call, and API field names match what the client expects.

**Why this priority**: The client is a separate repository. Vocabulary drift across the boundary produces bugs nobody sees until integration, but it does not block backend work starting.

**Independent Test**: Take the Arabic term list to a translator and to the client team; each term maps to exactly one entity or entity value, with no ambiguity about which concept it names.

**Acceptance Scenarios**:

1. **Given** the glossary, **When** the client team looks up which entities sync, **Then** they get the exact list, with the scope (wallet or user) each one belongs to.
2. **Given** the glossary, **When** a translator looks up جمعية, **Then** they find it defined as a specific recurring-transaction preset, not a generic group.

---

### User Story 4 - Prior art is reconciled once, not re-argued (Priority: P3)

Someone who remembers the earlier `DATA_MODEL.md` can see exactly how its eight MVP entities land in the new index, including the two that no longer exist, and why.

**Why this priority**: Prevents the old document being quietly treated as current. Needed for confidence, not to unblock work.

**Independent Test**: For each of the eight prior-art MVP entities, the glossary shows either its new name or a documented reason it is gone.

**Acceptance Scenarios**:

1. **Given** the mapping table, **When** a reader looks up Space, **Then** they find it dropped, with wallet-scoped membership named as the replacement.
2. **Given** the mapping table, **When** a reader looks up Posting, **Then** they find it retained with the category counter-leg from ADR-0012.

---

### Edge Cases

- **An entity two contexts both want**: Wallet Member is written by Sharing (invitation acceptance) but read by Wallets for every authorization check. The glossary names exactly one owner and records the other as a consumer.
- **Derived, not stored**: Entitlement, Period and Report are computed rather than persisted. The index marks them so nobody writes a migration for them.
- **Entities that are not syncable but the client still needs**: Plan, Subscription, Entitlement. The glossary states how the client gets them (read endpoints) without adding them to a change feed.
- **A shared wallet mixing two users' categories**: categories are user-scoped, so each member's transactions reference their own list. The glossary states what the other member sees (FR-019) and that neither can edit the other's categories.
- **A term that is a value, not an entity**: جمعية and قسط are recurring-rule presets, حصالة is a wallet type. The glossary keeps them out of the entity list and records where they belong.
- **Same word, two meanings**: "Period" as a budget window versus a subscription billing period. The glossary disambiguates or renames one.
- **Occurrence versus Transaction**: a recurring rule produces occurrences; a confirmed occurrence produces a transaction. The glossary states whether an occurrence exists as its own record before confirmation.
- **A banned synonym that is unavoidable externally**: Google Play calls its record a "purchase" and its payload a "purchase token". The glossary allows external vocabulary at the boundary while keeping internal names canonical.
- **Category as a posting account** (ADR-0012): a category is now referenced by ledger rows, so deleting one cannot cascade. The glossary records the lifecycle states that make this safe (active → archived).

## Requirements *(mandatory)*

### Functional Requirements

#### The index

- **FR-001**: The project MUST have one glossary document that is the single authoritative entity index, and every later spec MUST reference it rather than redefining entities.
- **FR-002**: The glossary MUST cover all 21 named entities: User, Device Session, Profile, Plan, Plan Feature, Subscription, Entitlement, Payment Claim, Redeem Code, Wallet, Wallet Member, Category, Transaction, Posting, Recurring Rule, Occurrence, Budget, Period, Invitation, Sync Cursor, Report.
- **FR-003**: Each entry MUST record: canonical English name, Arabic term where the UI shows one, one-sentence definition, owning bounded context, whether it is an aggregate root or a child (and of what), whether it is persisted or derived, whether it is syncable and in which scope, its lifecycle states, and which entities may reference it.
- **FR-004**: Each entity MUST have exactly one owning context, drawn from the nine in the constitution. Where another context reads or writes it, the entry MUST name that consumer and the application service through which the access happens.
- **FR-005**: The glossary MUST mark each entity syncable or not. Syncable entities MUST state their scope (wallet or user) and carry the sync fields required by the constitution: `version`, `change_seq`, `updated_at`, `deleted_at`.
- **FR-006**: Lifecycle states MUST be listed as a finite set with the transitions between them for every entity that has more than one state (for example Invitation, Subscription, Payment Claim, Occurrence).

#### Naming

- **FR-007**: The glossary MUST contain a banned-synonym table mapping each forbidden term to its canonical entity, covering at least: account → Wallet, purse/fund/pot → Wallet, collaborator/participant/sharer → Wallet Member, group/space/household → (no equivalent; wallet membership), tag/label → Category, entry/record/movement → Transaction, line/leg/split-line → Posting, schedule/repeat → Recurring Rule, tier/package → Plan, feature-flag → Plan Feature.
- **FR-008**: The glossary MUST state that external vocabulary (Google Play "purchase", InstaPay "transfer") is permitted only at the integration boundary and MUST NOT leak into internal entity or field names.
- **FR-009**: Field naming MUST be fixed for the recurring cross-entity concepts so no feature invents its own: money as an integer minor-unit amount plus a currency code, calendar dates, instants, the soft-delete marker, the version counter, and the sync sequence.

#### Arabic terms

- **FR-010**: The glossary MUST record the Arabic term beside the English entity or value for at least: محفظة (Wallet), حصالة (a savings-type wallet), جمعية (the rotating-savings recurring preset), قسط (the installment recurring preset), and MUST state for each whether it names an entity, a type value, or a preset.
- **FR-011**: The glossary MUST state that numerals are always Western (0–9) in API output regardless of locale, consistent with the constitution.

#### Reconciliation

- **FR-012**: The glossary MUST include a mapping table from the eight prior-art MVP entities (User, Space, SpaceMember, Wallet, Category, Transaction, Posting, Budget) to the new index, and MUST document every entity that is dropped, renamed or split, with the reason.
- **FR-013**: The glossary MUST apply the settled decisions recorded in the backlog without re-opening them: no Space; wallet-scoped membership with roles owner/editor/viewer; integer minor units only; sync fields on every syncable entity; the `balance_minor` and `opening_balance_minor` naming; a user timezone; statement-import entities deferred; postings with a category counter-leg.
- **FR-018**: Category and Budget MUST be recorded as **user-scoped**: owned by one user, carried on the user sync feed, and reused across all of that user's wallets. A budget MUST be described as limiting its owner's own spending, not a shared household limit.
- **FR-019**: The glossary MUST state how a member of a shared wallet sees a transaction categorised by another member, given that categories do not travel between users' feeds. The chosen mechanism MUST be named in the Category entry and MUST NOT make one member's categories editable by another.
- **FR-020**: Transaction MUST be recorded as carrying exactly one category in MVP, while noting that the ledger permits several category legs, so adding splits later is a contract change and not a data migration.
- **FR-014**: Entities that exist only for deferred scope (statement import, FX, savings goals) MUST be listed in a clearly separate "deferred, not built" section so nobody specs them by accident.
- **FR-015**: The glossary MUST NOT contradict the constitution or any Accepted ADR. Where it would, the correct outcome is a superseding ADR, not a glossary entry.

#### Boundaries

- **FR-016**: The glossary MUST NOT specify table schemas, column types, indexes, endpoints or payloads. Those belong to each feature's own spec and to the API conventions spec.
- **FR-017**: The glossary MUST NOT restate the wallet permission matrix, which the constitution assigns exclusively to the Wallets spec.

### Key Entities

The subject matter is the entity index itself. Its own structure is:

- **Glossary Entry**: one entity's canonical name, Arabic term, definition, owning context, aggregate/child role, persisted/derived, syncable + scope, lifecycle states, inbound references.
- **Banned Synonym**: a forbidden term, the canonical entity it maps to, and whether it is allowed at an external boundary.
- **Arabic Term**: the Arabic word, its English counterpart, and whether it names an entity, a type value or a preset.
- **Prior-Art Mapping**: an old entity name, its new home (or "dropped"), and the reason.
- **Field Convention**: a recurring concept (money, calendar date, instant, version, sync sequence, soft delete) and its fixed name and shape.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 21 entities have an entry, and 100% of entries carry every field required by FR-003 with no blanks.
- **SC-002**: Given six seeded synonym violations, a reviewer maps 6 of 6 to the canonical term using only the glossary.
- **SC-003**: All eight prior-art MVP entities appear in the mapping table, each either mapped or documented as dropped with a reason.
- **SC-004**: A builder handed one feature brief and the glossary alone names every entity involved, its owner and its sync status, with zero follow-up questions.
- **SC-005**: Zero contradictions between the glossary, the constitution and the Accepted ADRs, confirmed by a cross-artifact review.
- **SC-006**: Every entity marked syncable states its scope, and that list matches the two scopes the sync decision defines — no third scope appears.
- **SC-008**: A builder reading the Category entry can answer, without asking, what a member sees when their partner categorises a transaction in a shared wallet.
- **SC-007**: The four Arabic terms each resolve to exactly one concept, confirmed by a reader who did not write the glossary.

## Clarifications

### Session 2026-09-20

- Q: With `Space` dropped, are Categories and Budgets wallet-scoped or user-scoped? → A: **User-scoped.** One personal category set and one personal set of budgets per user, reused across all of that user's wallets, carried on the user sync feed.
- Q: Does MVP expose split transactions (one transaction across several categories)? → A: **No.** MVP models one category per transaction. The ledger still permits multiple category legs, so splits can be added later without a migration — only the contract and UI change.

### Consequences to settle in the glossary

User-scoped categories create one question the glossary must answer, because a shared wallet mixes two users' transactions:

- **A member cannot see the other member's category list.** Categories ride the user feed, so my partner's categories never reach my device, yet their transactions in our shared wallet reference them. The glossary must state how a member renders a transaction categorised by someone else. Recommended: the wallet feed carries a read-only projection of the categories referenced by that wallet's transactions (name, icon, colour, kind), owned by their creator and not editable by anyone else. Alternatives are a denormalised category name on the transaction, or copying categories between members.
- **Budgets are personal.** A budget limits its owner's own spending across their wallets, including their share of a shared wallet. It is not a shared household budget, and the glossary must say so plainly so Budgeting does not assume otherwise.

## Assumptions

- Output location: `docs/glossary.md`, next to `docs/adr/`, with the backlog and future specs linking to it. It is a reference document, not a spec artifact, so it lives in `docs/` where the client and landing-page teams can find it.
- Occurrence is a materialised record: a recurring rule generates occurrences ahead of time so the client can show and confirm them offline; confirming one produces a Transaction. The alternative (computing occurrences on the fly) is recorded as a rejected assumption for the Recurring spec to revisit if needed.
- Period is disambiguated: "budget period" is a named calendar window belonging to Budgeting, while a subscription's window is called a billing period and belongs to Plans & Billing. The word alone is never used as an entity name.
- Entitlement and Report are derived, not stored. Plan Feature is a data row inside a Plan rather than a separate table, but keeps a glossary entry because specs refer to it by name.
- Device Session is the Identity-owned record behind one device's access token, and is what the user sees in a "signed-in devices" list. It is user-scoped syncable so a revocation reaches other devices.
- Category nesting (`parent_id` in the prior art) is deferred for MVP: the glossary records the field as reserved and the nesting behaviour as out of scope, so a later spec can enable it without a rename.
- Category deletion is not a cascade: since postings reference categories, the lifecycle is active → archived, and a category that any posting references can be archived but never hard-deleted. This follows the ADR that made categories a posting account.
- Arabic terms beyond the four named ones are out of scope here; full translation lives in the localisation files.
- The glossary is written in English with Arabic terms quoted, because its readers are engineers; user-facing Arabic is a translation concern.
