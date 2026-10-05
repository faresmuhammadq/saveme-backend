# Summary: Back-office Operations (spec 021, B12)

**Date**: 2026-10-05 · **Status**: implemented (T001–T068), QA and browser validation done, committed on `main` (13d3ee9).

## What was built

The operators' working screens, all Livewire components extending `BackOfficeComponent` inside spec 018's guarded operator group, with no new dependency, API endpoint, OpenAPI change or domain event.

- **InstaPay claims** (`/admin/claims`, `/admin/claims/{claim}`): queue with status tabs and exact reference or exact email search (no address in rows, badge in the sidebar); review page shows the address (one `claim_address_read` per open), the 5-minute receipt link and duplicates; approve, reject and revoke code call Billing's `ClaimService` (spec 020).
- **Accounts** (`/admin/accounts`, `/admin/accounts/{user}`): lookup by exact address or account id only, with a budget of 30 per 10 minutes per operator (every account-page opening counts). The account page shows state, verification, plan and entitlements, subscriptions and four counts (wallets owned, wallets joined, other members, devices); manual grant, extend and revoke. It never shows the display name, wallet or category names, amounts, balances, notes, transactions, budgets, device names or the stored address.
- **Plan catalogue** (`/admin/plans`, `/admin/plans/{plan}`): English and Arabic names, display order, the InstaPay price (100–10,000,000 minor units), capability values and retiring a plan, with an optimistic version check; a `plan_updated` audit entry records the changed field names and the reason only, never values.
- **Audit log** (`/admin/audit`): filters, 50 per page with newer/older keyset paging; every query writes one `audit_read` whose subject is the reading operator.
- **Overview** (`/admin`): counts only, with an anonymity floor of 10 ("fewer than 10"), a *Needs attention* card (exact) and the low-codes alert first; manual grants are labelled "Granted", never "Paid".
- **Notification bell**: per-operator read marks (7-day unread window; opening a notification marks it read and goes to the account page).
- **Rules enforced in Billing's commands, not only on screens**: grants create `manual` subscriptions only (30/90/365 days, custom 1–3,650, or lifetime) on active accounts; extend only `manual` with an end date; revoke `manual` or `instapay`, never `google_play`; 3,650-day cap. The catalogue seeder is now create-missing-only, so a deploy never overwrites an operator's price edit.
- **Every state change** needs a second-factor code from the last 10 minutes (`RequiresRecentFactor`).
- **Migrations (additive)**: Identity `2026_10_10_100000` (audit subject type `plan` plus two audit indexes), Admin `2026_10_10_100001` (`operator_notification_reads`).
- **Docs**: security baseline (route budget, protected attributes, `plan` subject type, sensitive-act list; the `admin_action` paragraph was moved so `AuditVocabularyTest` still parses it — reviewed, wording complete), glossary, privacy commitments with a new English and Arabic promise (operators see counts and plan, never names or money), `checklists/fidelity.md` (deviations from the design boards).
- Updated existing tests deliberately: `OperatorActionsTest` (manual-subscription rules), spec 009's reseed behaviour, `SignInTest` ("Platform overview").

## Results

- **QA and browser validation** (Playwright at `http://saveme-backend.test`, throwaway operators, TOTP computed in a script): about 20 scenarios — overview, claim queue and review (approve and reject), account lookup, account page with grant/extend/revoke, plan catalogue and editor (review dialog, wrong code refused, then right code), audit log, bell, Arabic/RTL on the main screens — all pass after fixes except the 390 px check, which is partial. Guest and Sanctum-token requests redirect to sign-in on all 9 new routes. Reports `qa/qa-20261005-124000.md` and `test-reports/validation-20261005-123900.md`; 22 screenshots.
- Not run in the browser (covered by Livewire feature tests): quickstart steps 7, 9, 17, 22 (operator B half) and 23 (keyboard-only); the 30 per 10 minute lookup budget, audit paging at volume, stale plan versions and per-channel refusals were not exercised against the dev app.
- **Gates**: Pint clean; full suite green — 52 arch tests plus 3,187 passed, 5 skipped. Opt-in `BackOfficePerformanceTest`: every screen's p95 under 280 ms (audit log highest).
- **Four defects found and fixed in the browser pass**:
  1. `adminSelect.choose()` used `this.$el`, which inside an Alpine method is the clicked option, so the chosen value never reached Livewire (grant plan, audit filters and price currency kept their old values server-side). It now uses `$root`.
  2. A grant with no plan chosen answered 500 with an empty UUID; it now shows a field error and has a test.
  3. The account page showed raw keys like `admin::accounts.plan.features.sharing.enabled`; it now uses the `plans.capabilities.*` keys.
  4. Claim tabs overflowed the page at 390 px in Arabic; they now scroll inside their own row.
  `npm run build` was run; `public/build` is git-ignored, so deploy needs it too.

## Deployment

- Run the two `2026_10_10` migrations; `npm run build` for the shell assets.
- The catalogue seeder is create-missing-only (never overwrites operator edits).
- Operators need the usual enrolment (spec 018); enrol at least three before go-live.

## Assumed defaults

Owner away with delegated authority; each the conservative option (plan A-1…A-19, N-1…N-28).

1. Every operator may do everything (MVP owner decision); authorisation is re-checked on every request.
2. Every state change needs a code from the last 10 minutes, including access grants and plan edits (extends spec 020's rule).
3. Account lookup is by exact address or id only, 30 per 10 minutes per operator; every account-page opening counts, which prevents enumeration through direct URLs.
4. The account page shows counts only (P-18 rule 8; support access stays user-granted per the paused decision).
5. Recorded reads: `account_viewed`, `account_lookup_missed` (without the address), `claim_address_read` (once per open), `audit_read` (once per query).
6. Grants create `manual` subscriptions only; extend only `manual` with an end date; revoke `manual` or `instapay`, never `google_play`; enforced inside Billing.
7. Plan edits cover names, order, InstaPay price, capability values and retiring; the code, billing period, default flag and Play prices are not editable; the default plan's non-sharing capabilities are locked (P-09 rule 5); no plan creation.
8. A price change applies only to claims submitted afterwards; submitted claims stay approvable and the review screen flags a mismatch with the current price.
9. The overview shows counts only, floor of 10, computed on each load; no money, monthly-active or churn figures.
10. Read marks are per operator, not audited.
11. Searching claims by address is not a recorded read (rows carry no address) but spends the lookup budget.
12. Logs carry only the standard request line.
13. Overview tiles show the floor as the text "fewer than 10"; the oldest claim's age shows as "oldest 3 days ago".
14. "Granting" means `active` or `grace` and not yet ended, on a non-default plan, counted per person per channel; "in grace" counts subscriptions, not people; open re-enrolments exclude expired ones.
15. The factor re-confirm prompts only when the last proof is older than 10 minutes; an empty plan choice in the grant dialog reuses the "plan_not_grantable" message.
16. The notification store's old `latest`/`countSince` methods were replaced by operator-aware ones.

## Remaining risks and owner review

- **The new privacy promise (English and Arabic) is public copy and needs your review**, as do all operator-facing Arabic strings (the overview text "2 مطالبات" has no plural forms).
- Showing wallet counts to operators sits in some tension with the paused operator-data-boundary decision.
- Past prices cannot be recovered from the audit log (entries carry field names only).
- The seeder change alters spec 009's update-on-reseed behaviour; `OperatorSubscriptions` gained channel and account-state refusals.
- The lookup budget (30 per 10 minutes) may be tight on heavy support days.
- The shell header overflows the page by about 15 px in Arabic at 390 px on every page (predates this spec); the unsaved-changes bar on the plan editor appears only after a field loses focus.
- Out of scope, needing owner decisions: user-granted support windows, money aggregates, plan creation, a subscriptions index page.
- QA data left in the dev database (the audit log is append-only): both throwaway operators are deactivated; all QA claims are closed (one approved, two rejected); one QA account is pending deletion; an unverified QA account, a manual subscription and the Mailpit mails remain. Plan values were restored.
