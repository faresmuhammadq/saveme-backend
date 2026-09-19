# Quickstart: Validating the Constitution & ADR Set

Run from the repository root after `/speckit-implement` completes.

## Prerequisites

- Deliverables exist: `.specify/memory/constitution.md`, `docs/adr/README.md`, `docs/adr/0001…0011-*.md`.
- No application runtime needed: this is a documentation feature.

## 1. Structural checks (FR-001, FR-013, FR-014)

```bash
# Constitution filled — no template placeholders left
grep -nE '\[[A-Z_0-9]+\]' .specify/memory/constitution.md            # expect: no output
grep -nE '^\*\*Version\*\*: [0-9]+\.[0-9]+\.[0-9]+' .specify/memory/constitution.md   # expect: 1 line

# Exactly 11 ADRs, numbered 0001–0011
ls docs/adr/ | grep -cE '^00(0[1-9]|1[01])-.*\.md$'                   # expect: 11

# Each ADR has required sections and ≥2 rejected alternatives
for f in docs/adr/00*.md; do
  for s in '## Context' '## Decision' '## Alternatives Considered' '### Positive' '### Negative'; do
    grep -q "^$s" "$f" || echo "MISSING '$s' in $f"
  done
  [ "$(grep -c 'Rejected because' "$f")" -ge 2 ] || echo "FEWER THAN 2 ALTERNATIVES in $f"
done                                                                   # expect: no output

# Index lists every ADR
for f in docs/adr/00*.md; do grep -q "$(basename "$f")" docs/adr/README.md || echo "NOT INDEXED $f"; done
```

Expected: every command prints nothing except the counts noted.

## 2. Content checks

| Check | How | Expected |
|---|---|---|
| All 17 principles present (FR-003) | `grep -cE '^### P-(0[1-9]\|1[0-7])' .specify/memory/constitution.md` | 17 |
| 9 contexts defined (FR-004) | read Bounded Contexts table | 9 rows + Shared |
| O1 resolved (FR-020) | `grep -n 'Resolves.*O1' docs/adr/0004-*.md` | 1 line, status Accepted |
| Q1 encoded | `grep -n 'PostgreSQL' docs/adr/0001-*.md` | Decision names PostgreSQL; MySQL 8 + SQLite rejected |
| Q2 encoded | `grep -n 'VERSION_CONFLICT' docs/adr/0008-*.md` | whole-write rejection, no server merge |
| Codename not an identifier (P-13) | `grep -rniE 'saveme\|save_me\|SaveMe' docs/adr .specify/memory/constitution.md` | only prose/display mentions, none in prescribed paths/namespaces/tables |

## 3. Consistency (SC-006)

Run `/speckit-analyze`. Expected: zero CRITICAL/HIGH findings about contradictions between the constitution, the ADRs and the spec.

## 4. Reviewer exercise (SC-003)

Hand a reviewer only the constitution and ADRs, plus these 10 violations. Pass = 10/10 mapped correctly.

1. `$table->decimal('amount', 12, 2)` → P-03 / ADR-0003
2. `Budgeting` imports `App\Modules\Wallets\Infrastructure\Models\Transaction` → P-02
3. `if ($user->plan->code === 'pro')` → P-09 / ADR-0007
4. Transfer stored as two independent transactions → P-04 / ADR-0004
5. Rejecting an expense because the balance would go below zero → P-05 / ADR-0005
6. `POST /transactions` returning a server-generated id → P-06 / ADR-0002
7. Stale push merged field-by-field on the server → P-07 / ADR-0008
8. Report total read from `wallets.balance_minor` → P-05 / ADR-0005
9. Listener runs inside the DB transaction and sends a push → P-11 / ADR-0009
10. `created_at` stored as local Cairo time → P-12 / ADR-0011

## 5. Newcomer exercise (SC-004)

Ask 10 "why" questions, one per ADR 0002–0011. Pass is at least 9/10 answered correctly from the ADRs alone within 30 minutes.

## 6. First-plan gate (SC-005)

The next `/speckit-plan` (any feature) produces a Constitution Check table per [contracts/constitution-check.md](./contracts/constitution-check.md) with 17 rows and no "unclear" results.
