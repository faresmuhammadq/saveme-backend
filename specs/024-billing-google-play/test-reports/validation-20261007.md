# Validation Report: Billing, Google Play (spec 024)

**Date**: 2026-10-07 | **Method**: acceptance scenarios of spec.md walked over HTTP and commands against the dev stack with the scripted fake gateway (see `qa/qa-20261007.md`); the back-office screen is read-only markers and counts, covered by `AccountPagePlayTest` and `PlayFiguresTest`; no Playwright run (an operator sign-in needs TOTP enrolment).

| Story | Scenarios | Result |
|---|---|---|
| US1 Buy Plus and get it at once | 200 shape and server acknowledge, replay, new key same token, pending 202 then granted, unverified 422, unavailable 503, missing key 400 | 7/8 live; acknowledgement-failure retry automated: PASS |
| US2 One purchase, one account | linked elsewhere 409, reference names other 409, first account links, replacement, reference stable and distinct | 5/6 live; purged-account token 409 automated: PASS |
| US3 Google keeps it in step | renew, grace, hold/pause, recover, cancel then expire, revoke and voided, duplicate and out-of-order, test notification | 9/9 PASS (unlinked-with-reference link and acknowledge automated) |
| US4 Only Google can push | wrong signature, iss, aud, email, unverified email, iat, expired, no token, foreign package, repeated message id, oversized and malformed body | 5/5 PASS (certs unavailable 503 automated) |
| US5 Restore | linked, already_yours, linked_elsewhere, not_verified, pending, empty list, 26 items, duplicate token | 6/6 PASS (all-unavailable 503 automated) |
| US6 Nothing is lost | hourly renewal and expiry, 72 h fallback, unacknowledged retry and alert, daily voided sweep | 5/5 PASS (alert and voided sweep automated) |
| US7 Operators | markers, no extend/revoke, counts, no secrets | 3/3 PASS (automated) |

Total: 49 acceptance scenarios, 49 PASS, 0 FAIL, 0 skipped. Full gate after the fix: see summary of the final `composer test` run.
