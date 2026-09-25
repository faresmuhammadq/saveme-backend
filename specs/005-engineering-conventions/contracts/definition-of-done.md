# Contract: Definition of Done

Normative. Transcribed into `CONTRIBUTING.md`, which holds the published eighteen-item form: two items were added during implementation — plan codes (P-09, split pipeline/reviewer) and no new dependency without approval. Every item has exactly one checker.

| # | Item | Applies when | Checked by |
|---|---|---|---|
| 1 | Code sits in the module layout and respects layer rules | always | pipeline |
| 2 | Formatting and static analysis (level 8) pass | always | pipeline |
| 3 | A migration for each persisted change, in the module's `Infrastructure/Database/Migrations` | schema changes | reviewer |
| 4 | No float, double, real or decimal column for money | any migration | pipeline |
| 5 | A path file `docs/openapi/paths/<feature>.yaml`, referencing shared components | endpoint added or changed | reviewer |
| 6 | The API definition loads, every reference resolves, every code is catalogued | always | pipeline |
| 7 | A policy for each resource | new resource | reviewer |
| 8 | Unit tests for each domain rule | domain rule added | reviewer |
| 9 | Feature tests for each application service | service added | reviewer |
| 10 | Contract tests for each endpoint, including every documented error | endpoint added | reviewer writes; pipeline validates against the definition |
| 11 | A test that fails when each new invariant is broken | invariant added | reviewer |
| 12 | A seeder entry for reference data, or "N/A" stated in the PR | always stated | reviewer |
| 13 | A glossary entry for any new term | new term | reviewer |
| 14 | The security review checklist applied | endpoint or stored attribute added | reviewer |
| 15 | Commit messages in the conventional shape | always | pipeline |
| 16 | A decision record when a decision extends or contradicts an Accepted one | as needed | reviewer |
