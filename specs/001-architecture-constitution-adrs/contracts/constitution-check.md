# Contract: Constitution Check (per feature plan)

Every `plan.md` produced by `/speckit-plan` after ratification MUST contain this section, and it replaces the generic placeholder in `.specify/templates/plan-template.md`.

```markdown
## Constitution Check

*Constitution vX.Y.Z. GATE: before Phase 0 and again after Phase 1.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | … |
| P-02 Module boundaries | PASS | Touches Budgeting; reads balances via `Wallets\Application\…` |
| … one row per Active principle … | | |
```

## Rules

- Result ∈ `PASS`, `FAIL`, `N-A`. `N-A` needs a one-line reason (e.g. "no money handled").
- Any `FAIL` on a non-negotiable principle blocks the plan. It needs either a plan change or a constitution amendment first.
- Any `FAIL` on another principle needs a row in Complexity Tracking.
- Evidence cites concrete plan elements (module, entity, endpoint), not restated rules.
- The check is repeated after Phase 1, and both results are kept.
