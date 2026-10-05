# Quickstart: Back-office Operations (spec 021)

How to prove the feature end to end. Shapes and copy are in [contracts/](./contracts/); rules in [data-model.md](./data-model.md).

## Prerequisites

```bash
dev project open                      # app at http://saveme-backend.test, mail at http://mailpit.test
dev migrate                           # includes 2026_10_10_100000 (Identity) and 2026_10_10_100001 (Admin)
dev db:seed --class='App\Modules\Billing\Infrastructure\Database\Seeders\BillingSeeder'
dev admin:operator:create             # if no enrolled operator exists; enrol it in the browser (spec 018 quickstart)
```

Create data through the real surfaces (no tinker, no new commands — the admin command set is fixed by spec 018's arch test):

1. Register two accounts through `POST /api/v1/auth/register` (spec 007) and verify one via the Mailpit link.
2. Submit three InstaPay claims through `POST /api/v1/billing/instapay/claims` (`X-Client-Platform: web`, one `Idempotency-Key` each; spec 020 quickstart), two sharing one reference, one with a small PNG receipt.
3. Create a wallet and invite/accept the second account to share it (spec 016 quickstart), so counts are non-zero.

## Automated gates

```bash
dev exec -- vendor/bin/pest tests/Unit/Admin tests/Unit/Billing/PlanEditTest.php tests/Unit/Identity
dev exec -- vendor/bin/pest tests/Feature/Admin tests/Feature/Billing tests/Feature/Identity tests/Feature/Wallets/MembershipCountsTest.php
dev exec -- vendor/bin/pest tests/Arch
dev exec -- vendor/bin/pest tests/Contract/OpenApi     # must stay green: no API change
dev exec -- composer test                              # whole gate before handing over
```

## Browser validation (Playwright, English then Arabic, 1280 px and 390 px)

Sign in as the enrolled operator. Each step names the expected outcome; check `audit_entries` with a read-only query afterwards (`event`, `detail->>'action'`, `subject_type`, `actor_id`).

| # | Steps | Expected |
|---|---|---|
| 1 | Sidebar | *InstaPay claims* (badge 3), *Accounts*, *Plan catalogue*, *Audit log* present; Arabic mirrors |
| 2 | Claims → Awaiting review | 3 rows, oldest first, no email anywhere in the table; two "Shared reference" pills |
| 3 | Search the reference in lower case with a hyphen | only the two sharing claims |
| 4 | Open the claim with a receipt | address shown; one new `claim_address_read`; reload the page → one more (a new open) |
| 5 | Get receipt link → open | image in new tab; one `claim_receipt_viewed` |
| 6 | Approve (factor older than 10 min) | factor prompt; wrong code → error, nothing changes; right code → approved, code issued, mail in Mailpit, one `claim_reviewed` |
| 7 | Approve the duplicate | refused: "Another claim with this reference is already approved." |
| 8 | Reject the third with *Amount doesn't match* and a note | rejected; mail without the note; `claim_reviewed` has no note |
| 9 | Revoke the approved claim's code with a reason | code revoked; no mail |
| 10 | Accounts → search the verified address in upper case | account page; "Found by …" shows the normalised address; one `account_viewed`; privacy note; counts 1/0/1/≥0 as seeded; no wallet name, display name or device name in the page source |
| 11 | Search a random address, then a random UUID | "No account matches." twice; two `account_lookup_missed` without the address |
| 12 | Grant Plus · Monthly 30 days with a reason | Plus governs; `entitlement_changed` grant |
| 13 | Extend it 30 days; revoke it | end +30 d; revoked; Free governs; two entries |
| 14 | Plans → Plus · Monthly → price 159.00, members 6 → Review & save | summary lists both, shows submitted-claim count; after factor "Plan saved."; `plan_updated` with `fields` and reason, no `15900` anywhere in the entry |
| 15 | `GET /api/v1/plans` | new price and limit |
| 16 | Re-run the BillingSeeder | price still 159.00 |
| 17 | Open the editor in two tabs; save in tab A, then in tab B | tab B: "changed by someone else" |
| 18 | Free plan editor | non-sharing rows locked "Fixed for the default plan"; no *Retire* |
| 19 | Audit log | last 7 days; note shown; one `audit_read`; filter subject = the account id → only its entries, one more `audit_read`; Older/Newer each add one; expanding adds none |
| 20 | Range of 401 days | field error; no new `audit_read` |
| 21 | Overview | counts with "fewer than 10" for small populations; needs-attention exact; no entry written |
| 22 | Bell as operator A: open one, *Mark all read*; sign in as operator B | A's count 0; B's unchanged |
| 23 | Keyboard only through steps 2, 6, 12, 14, 19 | every control reachable, focus trapped in dialogs and restored |
| 24 | 390 px width | tables scroll inside cards; no page-level horizontal scroll |

## Performance (SC-005)

`tests/Feature/Admin/BackOfficePerformanceTest.php` (opt-in, like spec 020's) seeds 10,000 accounts, 2,000 claims and 100,000 audit entries and asserts each screen's first render p95 < 1.5 s with Xdebug off.
