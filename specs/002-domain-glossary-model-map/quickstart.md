# Quickstart: Validating the Domain Glossary

Run from the repository root after `/speckit-implement` writes `docs/glossary.md`.

## Prerequisites

- `docs/glossary.md` exists. No runtime, no database — this is a documentation feature.

## 1. Structural checks (FR-002, FR-003, FR-005)

```bash
# All 22 entries present (21 named entities + Category Reference).
# The count is 23: the "How to read an entry" fenced example also starts with "### ".
grep -cE '^### ' docs/glossary.md                      # expect: 23

# Every entry carries every required field (22 entries + 1 in the fenced example = 23).
# This audit is the reliable check — it names any entry missing a field:
awk '/^### /{n=$0; c=r=p=s=st=rb=0}
     /^- \*\*Context\*\*/{c=1} /^- \*\*Role\*\*/{r=1} /^- \*\*Persistence\*\*/{p=1}
     /^- \*\*Sync\*\*/{s=1} /^- \*\*States\*\*/{st=1} /^- \*\*Referenced by\*\*/{rb=1}
     /^---$/{if(n!=""){m="";if(!c)m=m" Context";if(!r)m=m" Role";if(!p)m=m" Persistence";
     if(!s)m=m" Sync";if(!st)m=m" States";if(!rb)m=m" ReferencedBy";
     if(m!="")print n" MISSING:"m; n=""}}' docs/glossary.md   # expect: no output

# Only the two legal sync scopes appear
grep -oE '^\- \*\*Sync\*\*: .*' docs/glossary.md | sort | uniq -c
# expect only: "no", "yes — wallet scope", "yes — user scope", "yes — wallet scope, read-only"

# Every context is one of the nine
grep -oE '^\- \*\*Context\*\*: [A-Za-z &]+' docs/glossary.md | sed 's/.*: //' | sort -u
# expect a subset of: Identity, Wallets & Ledger, Budgeting, Sharing, Plans & Billing, Sync, Reporting, Notifications, Admin

# Required document sections (FR-001 … FR-014)
for s in 'Purpose' 'How to read' 'entity index' 'Arabic terms' 'Banned synonyms' 'Field conventions' 'Prior-art' 'Deferred'; do
  grep -qi "$s" docs/glossary.md || echo "MISSING SECTION: $s"
done
```

Expected: the counts above, and no "MISSING SECTION" lines.

## 2. Content checks

| Check | How | Expected |
|---|---|---|
| Sync scope split (SC-006) | count `user scope` / `wallet scope` entries | 4 user, 7 wallet — matches data-model.md Part B |
| Posting never exposed (P-04) | read the Posting entry | Sync = no, with "never exposed or synced" in Notes |
| Entitlement derived (P-09) | read the Entitlement entry | Persistence = derived; Notes name the entitlement service as the only access path |
| Sync Cursor not a table (R-6) | read the Sync Cursor entry | Persistence = client-held; Notes point at `change_seq` |
| Category Reference (FR-019, SC-008) | read the Category entry | states what a member sees when a partner categorises a transaction, and links to Category Reference |
| No permission matrix (FR-017) | `grep -iE 'owner.*editor.*viewer' docs/glossary.md` | roles may be *named*; no table of role × action |
| No schema leak (FR-016) | `grep -nE 'bigint\|varchar\|CREATE TABLE\|/api/' docs/glossary.md` | no output |
| Codename absent (P-13) | `grep -ni 'save.\?me' docs/glossary.md` | no identifier use |
| Arabic terms (FR-010, SC-007) | read the Arabic table | 4 rows, each labelled entity / type value / preset |
| Prior art (FR-012, SC-003) | read the mapping table | all 8 entities mapped or marked dropped with a reason |

## 3. Reviewer exercise (SC-002)

Give a reviewer only `docs/glossary.md` and these six changes. Pass = 6/6 mapped to the canonical term.

1. Migration creating a `collaborators` table → **Wallet Member**
2. `AccountBalanceService` in `app/Modules/Wallets` → **Wallet**
3. A `tags` relation on Transaction → **Category**
4. `RecurrenceScheduler` producing `instances` → **Recurring Rule**, **Occurrence**
5. `$table->bigInteger('amount_cents')` → **`<name>_minor`** field convention
6. A `spaces` table grouping wallets → no equivalent; wallet membership (dropped in prior-art mapping)

## 4. Builder exercise (SC-004)

Hand a builder the brief *"budgets per category per period"* and only the glossary. Pass = they name, with no follow-up questions:

- entities: Budget (Bud, user-scoped syncable), Budget Period (derived), Category (W&L, user-scoped syncable), Transaction and Posting for actuals
- that Budgeting reads Category and transaction totals through Wallets & Ledger query services, never its models
- that a budget is personal, not a shared household limit

## 5. Consistency (SC-005)

Run `/speckit-analyze`. Expected: zero CRITICAL/HIGH findings between spec, plan, tasks, the constitution and the ADRs.

Manual cross-check of the four claims most likely to drift:

```bash
grep -n 'wallet XOR category\|wallet_id.*category_id' docs/glossary.md   # matches ADR-0012
grep -n 'balance_minor' docs/glossary.md                                  # matches ADR-0005, not cached_balance
grep -n '90 days' docs/glossary.md                                        # tombstone retention matches ADR-0008
grep -n 'owner.s entitlements\|owner.s plan' docs/glossary.md             # shared wallet governed by owner (ADR-0007)
```
