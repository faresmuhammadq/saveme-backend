# B11 · Billing — InstaPay claims

**Call with**: `/speckit-specify B11` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

On the landing page (never in the app) a visitor picks a plan, sees the InstaPay address and the exact amount, transfers, and submits a claim: email (must match an account), plan, amount, InstaPay reference number, optional screenshot. `POST /billing/instapay/claims` creates a `payment_claim` (pending). An admin reviews it in spec 12; on approval the system creates an `instapay` subscription for the plan's period and emails a single-use **redeem code**; the user enters it in the app via `POST /billing/redeem` which attaches the subscription to the logged-in account. Claims expire after 7 days if unreviewed; users get a reminder email 7 days before period end with a link to renew.

## Considerations

This flow exists because no commercial registration is in place; the spec must state it is web-only so the Android app stays compliant with Play Billing policy (the app offers only "Enter code" and "Restore purchases"). Rate-limit claim submission and validate the reference format to keep review load down. Store screenshots privately with short-lived signed URLs. Redeem codes are hashed at rest, 30-day validity, bound to the claim's email but redeemable by any logged-in account with that email. Overlap with an active Play subscription follows the spec 3 precedence rule. Refunds are manual and out of scope.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| "Email must match an account" | Security baseline: no surface may reveal whether an address is registered | The claim form must answer the same either way |
| Landing page | P-18: the website is an API client; nothing user-facing is server-rendered here | The claim form lives in the landing-page project and calls this endpoint |
| Admin review "in spec 12" | Review screens build on D1 | Ship the approve/reject domain command here; the screen lands in B12 |
| Screenshot storage | Security baseline never-log list covers receipt images; nothing fixes their retention | Add receipt retention to the baseline's table |
