# Contract: Pipeline Stages

Normative. One job, steps in this order. A failing step stops the rest, so the failure names its stage.

| # | Step | Command | Fails on |
|---|---|---|---|
| 1 | Formatting | `composer test:lint` | any Pint change |
| 2 | Static analysis | `composer test:types` | PHPStan level 8 |
| 3 | Architecture | `composer test:arch` | boundaries, migrations, entitlements |
| 4 | Database engine | `SELECT version()` → job summary | never |
| 5 | Test suite | `composer test:suite` | Unit, Feature, Contract |
| 6 | Commit messages | pattern over `base..head` | pull requests only |

The API definition checks run inside step 5 as `tests/Contract/OpenApi`, and are also runnable alone as `composer test:api`.

`composer test` runs 1, 2, 3 and 5 in order locally — the whole gate P-16 names.

No image build step exists until a deployment target is chosen.
