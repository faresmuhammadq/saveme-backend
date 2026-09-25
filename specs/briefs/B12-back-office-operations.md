# B12 · Back-office operations

**Call with**: `/speckit-specify B12` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A Filament panel behind a separate `admin` guard with 2FA: review InstaPay claims (approve/reject with reason), grant/extend/revoke manual subscriptions, look up a user and see their subscriptions, entitlements, wallets count and members, edit the plan catalog and prices, view the security audit log. Every admin action writes an audit row.

## Considerations

Keep it thin — no editing of user financial data. Approving a claim is the only path that creates an `instapay` subscription; it must be a domain command reused by tests, not Filament-only code. Seed one admin via artisan command.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Filament | **P-18**: the back office is Livewire and Blade; a second interface stack needs an amendment | Build on the D1 primitives instead |
| Separate guard, 2FA, seeding an admin | Delivered by D1 | Out of this spec's scope |
| "Look up a user and see … wallets count and members" | P-18 rule 8: no screen shows an individual user's amounts, balances or notes; the paused operator-access decision is in memory | Counts and membership are fine; balances are not |
| "View the security audit log" | Security baseline: reading the audit log is itself an `audit_read` event | Required |
