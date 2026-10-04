# Quickstart: Reports

**Feature**: [spec.md](./spec.md) · **Contract**: [contracts/reports-api.md](./contracts/reports-api.md)

Validation guide for after implementation. Everything runs in the container (host PHP has no `pdo_pgsql`).

## Prerequisites

```bash
dev project open
dev migrate
dev db:seed            # platform categories (spec 011)
```

A verified user with three EGP wallets (cash with opening balance 100000, bank, credit card) and one USD wallet, and a bearer token (spec 012's quickstart); headers `X-Client-Platform: android`, `X-Client-Version: 1.0.0` on every request.

## Gates

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi          # contract skeleton, v1.11 indexed
dev exec -- vendor/bin/pest tests/Unit/Reporting
dev exec -- vendor/bin/pest tests/Feature/Reporting
dev exec -- vendor/bin/pest tests/Contract/Reporting
dev exec -- vendor/bin/pest tests/Arch
REPORTS_PERF=1 XDEBUG_MODE=off dev exec -- vendor/bin/pest tests/Feature/Reporting/ReportPerformanceTest.php
dev exec -- composer test                                   # whole gate before a PR
```

## Scenarios

1. **Summary** — in cash: income 1000000, expenses 300000 and 50000 this period, expense 20000 last period, transfer 200000 cash → bank this period. `GET /wallets/{cash}/reports/summary` → income 1000000, expenses 350000, net 650000, transfers_out 200000, balance_start 80000, balance_end 530000; `range` = the current Budget Period.
2. **Explicit range** — `?from=2026-09-01&to=2026-09-30` → `range` echoed; `?from=2026-09-01` alone, `?date=…&from=…&to=…`, `?from=2026-10-02&to=2026-10-01`, a 400-day span → `400 INVALID_QUERY_PARAMETER`.
3. **Truth** — corrupt the cash wallet's `balance_minor` in tinker; the summary is unchanged; with `to` = today, `balance_end` equals opening + Σ postings.
4. **By category** — expenses in platform Groceries (two), Transport and an own "Gym": items labelled `groceries`, `transport`, `<gym id>`, largest first, `total` = Σ items; no share member.
5. **Trend** — `GET /wallets/{cash}/reports/trend?months=3` → three periods, empty months zero-filled; `months=13` → 400.
6. **Cross-wallet** — `GET /reports/summary` → EGP; the cash → bank transfer appears nowhere; a transfer from bank into a partner's wallet you are not a member of counts as `transfers_out`; `meta.excluded_wallets` = the USD wallet. `?currency=USD` → only the USD wallet; EGP wallets excluded.
7. **Shared wallet** — a second member records an expense in a shared wallet; both members' wallet reports show it, with identical figures; a non-member gets 404.
8. **Planned** — a monthly rent rule with an occurrence later this period: `?include_planned=true` → `planned.expenses` = rent; actual figures and balances unchanged; without the flag `planned` is null.
9. **Leaving** — leave the shared wallet: it drops out of `/reports/*`; its wallet reports → 404.
