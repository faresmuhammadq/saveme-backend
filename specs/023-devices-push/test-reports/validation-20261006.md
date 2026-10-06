# Validation Report: Devices & Push Notifications (spec 023)

**Date**: 2026-10-06 | **Method**: acceptance scenarios of spec.md walked over HTTP and commands against the dev stack (no screen needed: the only back-office change is two read-only counts, covered by `NotificationFiguresTest`; no Playwright run).

| Story | Scenarios | Result |
|---|---|---|
| US1 Register a device | register own session, other/unknown 404, token never returned, sealed at rest, one per session/token, DELETE idempotent, removed on sign-out/revoke/270 days | 7/7 PASS (purge step by automated test) |
| US2 Preferences | defaults v1, whole-object save, 409 with `current`, 422 validation, quiet hours validation | 5/5 PASS |
| US3 Recurring reminders | due today, once only (second pass nothing); yesterday, refused, 09:00, summary, viewers by automated tests | 2/2 live, rest automated PASS |
| US4 Budget overrun | strictly above 100 percent, exactly 100 silent, once per period, limit change | 4/4 PASS |
| US5 Sharing | invitation (email, verified addressee), role change, removal, ownership | 4/4 PASS |
| US6 Billing/export/deletion | ending, lapsed, export ready, deletion reminder | 4/4 PASS |
| Delivery rules | quiet hours hold and release with collapse, category off, 10/hour cap, generic ids-only payloads | 4/4 PASS |
| Safety | token absent from logs/jobs/audit, boot guards, RS256 assertion verified with openssl | 3/3 PASS |

Total: 29 scenarios, 29 PASS, 0 FAIL, 0 skipped. Detail in `specs/023-devices-push/qa/qa-20261006.md`.
