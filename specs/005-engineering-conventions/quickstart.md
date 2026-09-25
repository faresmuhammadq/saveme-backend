# Quickstart: Validating the Engineering Conventions

**Feature**: `005-engineering-conventions` | **Plan**: [plan.md](./plan.md)

Unlike specs 001–004, this feature ships runnable gates, so validation is running them. Everything runs inside the worktree environment because host PHP has no `pdo_pgsql`.

## Prerequisites

```bash
dev up
dev project env --yes
dev migrate
```

## 1. The whole gate passes on the current tree

```bash
dev exec -- composer test
```

**Expected**: formatting, static analysis at level 8, architecture, and the test suite all pass.

## 2. Each gate bites — seeded violations (SC-002)

Create each file, run the named command, confirm the failure names the rule, then delete the file.

| # | Seed | Command | Expected failure names |
|---|---|---|---|
| 1 | a migration with `$table->decimal('amount', 12, 2)` | `dev exec -- composer test:arch` | P-03 and the file:line |
| 2 | `app/Modules/Wallets/Domain/Bad.php` importing `Illuminate\Database\Eloquent\Model` | `dev exec -- composer test:arch` | P-02, Domain → Illuminate\Database |
| 3 | `app/Modules/Budgeting/Application/Bad.php` importing `App\Modules\Wallets\Infrastructure\Models\Wallet` | `dev exec -- composer test:arch` | P-02, cross-module Infrastructure |
| 4 | `app/Modules/Wallets/Application/Bad.php` importing `App\Modules\Billing\Domain\PlanCode` | `dev exec -- composer test:arch` | P-09 |
| 5 | a `$ref` to a missing component in `docs/openapi/paths/_template.yaml` | `dev exec -- composer test:api` | the file and the reference |
| 6 | an error code not in the catalogue in a path file description | `dev exec -- composer test:api` | the code |

**Expected**: 6 of 6 fail, each naming its rule, and the tree is clean again afterwards.

## 3. Architecture tests pass on an empty tree (SC-003)

With `app/Modules/` absent, `dev exec -- composer test:arch` passes. With seed 2 present, it fails.

## 4. Contract validation works

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi
```

**Expected**: Spectator loads `docs/openapi/openapi.yaml` without error, and the skeleton checks pass.

## 5. Commit message check

```bash
echo "added stuff" | grep -Eq '^(feat|fix|docs|chore|refactor|test|build|ci)(\([a-z0-9-]+\))?!?: .{1,}' && echo pass || echo "rejected, as it should be"
```

## 6. New-contributor walk (SC-004)

From a fresh clone, follow `docs/engineering-conventions.md` → *Getting started* top to bottom. **Expected**: green suite in under thirty minutes, no questions.

## 7. Spec template (SC-005)

`grep -c '^### ' .specify/templates/spec-template.md` shows the nine project headings present, and the workflow's mandatory sections remain.
