# Feature Specification: Export & Data Deletion

**Feature Branch**: `022-export-data-deletion`

**Created**: 2026-10-06

**Status**: Draft

**Input**: Brief B15 ([specs/briefs/B15-export-data-deletion.md](../briefs/B15-export-data-deletion.md)): "Users export a wallet or all their data as CSV/XLSX (`POST /exports` → job → signed download URL, 24h) and complete account deletion after the grace period: owned wallets with other members get ownership transferred to the oldest editor (or are deleted if none), subscriptions are canceled at provider where possible, personal data is purged, and an anonymised audit trail remains." Plus its considerations (queued, one active export per user, deletion a scheduled idempotent resumable job, Play subscriptions cannot be cancelled server-side), the owner decisions of 2026-09-25, its Reconcile table, every "Inherited from B…" note, and the paired client brief [C15](../../../saveme-kmp/specs/briefs/C15-export-deletion.md) "Needs from the backend" (export job shape and states, the one-active-export error, the ownership-choice fields, the fallback when the only other member is a viewer).

> **Nature of this feature.** Spec 007 built the deletion *request* (password-confirmed, 30-day grace period, cancel explicitly or by signing in). This feature builds what the request promised: **taking a copy** of one's data, and **the purge** that runs when the grace period ends. It is the feature that makes the privacy page's "Leaving" section true.
>
> **Where the brief and the ratified decisions differ, the ratified side wins unless this spec supersedes it by amendment:**
>
> | Brief | In force | Here |
> |---|---|---|
> | Owned shared wallets transfer to the oldest editor, or are deleted if none | Security baseline, Leaving a shared wallet: "wallets they own — removed with them"; owner decision 2026-09-25: ownership transfers, never deleted | **Ownership transfers** — to the only other member whatever their role; with several, to the member the departing person chose, else the oldest editor, else the oldest viewer. Only a wallet with no other member is deleted. The baseline's "Leaving a shared wallet" and the privacy commitments' "Leaving" are **amended** in both languages, and ADR-0024 records the rule |
> | CSV/XLSX | No spreadsheet package installed (dependency approval needed); baseline Export rule 2: "a spreadsheet-openable file of transactions" | **CSV only** for the spreadsheet; complete structured data as JSON; both in one ZIP archive built with PHP's bundled `zip` extension. No new dependency; XLSX is an open question |
> | One active export per user | `409 EXPORT_IN_PROGRESS` reserved since v1.1 | Used, and its body carries the export already in progress as `current`, so a retried request is safe without an idempotency key |
> | Signed download URL, 24h | Laravel's private `local` disk and signed routes (spec 020's receipt link) | The file lives 24 hours. The app gets a fresh 15-minute signed link on every status read; the "ready" mail carries a link valid until the file's expiry |
> | Anonymised audit trail | Audit entries carry identifiers only, append-only, 400 days | Compatible: audit entries are never edited; they keep the departed person's id, which resolves to nobody once the account row and its key are gone |
> | Transactions' author "anonymised to the stable departed-member marker" (B6 note) | Glossary: `created_by` "never changes"; owner decision: "the departing user's transactions stay with their reference" | **The reference stays unchanged.** The marker *is* the departed id: after the purge no record maps it to a name, an address or a profile, so it is stable and cannot be resolved to a person. Clients render "Former member" for an author with no live membership |
> | B14 note: "the purge then removes the rows and the `users` row last" | Spec 007 data model: state `purged` with a null address; spec 021 renders "Deleted" for it | The `users` row is **reduced to a husk** (id, `purged`, instants, counters — no attribute, no key) rather than removed, and deleted 400 days later. Everything the B14 note lists (profile, sessions, categories, memberships) still goes, memberships tombstoned first |
| Subscriptions canceled at provider where possible | Google Play has no server-side cancel in this backend (B10 not built); `instapay` and `manual` are internal | Internal subscriptions are **ended** at the purge; a Google Play subscription cannot be ended by us, and the request mail, the reminder and the completion mail say so in both languages |

## Clarifications

### Session 2026-10-06

Owner away with delegated authority; each answer is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: What is in an export, and in what form? → A: **One ZIP archive** holding complete structured data as JSON (one file per record kind, the same field names and money shape as the API, without sync positions) plus **`transactions.csv`** — a spreadsheet-openable file of transactions (UTF-8 with byte-order mark, comma-separated, RFC 4180 quoting, a header row). No XLSX (new dependency). *Assumed default.* (FR-001–FR-006)
- Q: What does an **account** export cover? → A: Everything the person entered **and can still see**: account and profile, budget start-day history, device sessions (names, platform, times — never a credential), subscriptions, their own categories, budgets, their own memberships, invitations they sent (without the invited address), and **every wallet they are an active member of** with all its transactions, recurring rules and occurrences — including entries other members wrote, because the person already sees them and the wallet's history is not readable without them. Wallets they left or were removed from are not included. *Assumed default.* (FR-002, FR-003)
- Q: How are other members represented? → A: **Never by name, address or identifier.** Each transaction, rule and occurrence carries `author: "me"` or `author: "other_member"` instead of `created_by`; a wallet carries the person's own role and the member count. Category names shown on another member's entries are the names the person already sees (the wallet's category references). *Assumed default.* (FR-004)
- Q: Can a single wallet be exported? → A: **Yes**, `scope: "wallet"` with a `wallet_id` of a wallet the person is an active member of (any role); it contains that wallet, its transactions (JSON and CSV), rules and occurrences, and the categories its entries use as the person sees them. A non-member gets `404`. *Assumed default.* (FR-007)
- Q: Is export gated by plan? → A: **No.** Taking a copy of one's own data is a privacy commitment and P-09 rule 5 limits the free tier only on sharing; `export.enabled` is not consulted (open question 2 records the flag's future). Not behind the verification gate either (baseline C2: reads are not gated). Served during the deletion grace period without moving the date (baseline Export rule 4). *Assumed default.* (FR-008, FR-009)
- Q: How is an export produced and delivered? → A: **Asynchronously**: the request answers `202` with the export in `queued`; a queued job builds it (`running` → `ready` or `failed`). The app reads its status; when `ready`, every read carries a **fresh signed download link valid 15 minutes**. One mail tells the person it is ready, in their profile language, with a signed link valid **until the file expires**. The file is kept **24 hours** after it is ready, then deleted (`expired`). *Assumed default.* (FR-010–FR-016)
- Q: Limits? → A: **One active export** (`queued` or `running`) per person: a second request is `409 EXPORT_IN_PROGRESS` with the active export as `current`. **At most 5 accepted export requests per 24 hours** per person (`429 RATE_LIMITED`, `Retry-After`). The signed download route allows **20 downloads per 10 minutes per origin**. An export stuck `queued` or `running` for 60 minutes is marked `failed` so it never blocks the next one. *Assumed default.* (FR-017–FR-019)
- Q: Is the archive encrypted at rest? → A: **No** — it sits on the existing private disk, outside any public path, reachable only through a signed link, and is deleted 24 hours after it is ready and immediately when the account is purged. The baseline's protected-attributes table gains a row saying exactly that. Encrypting it would require a custom streaming download instead of the signed file route and buys little for a 24-hour file. *Assumed default.* (FR-020)
- Q: Which audit entries? → A: `export_requested` when a request is accepted (detail: `scope`), and `export_delivered` on the **first** successful download of that export (detail: `export_id`) — exactly one each, never the wallet name, an amount or a link. *Assumed default.* (FR-021)
- Q: Who becomes owner of a shared wallet the departing person owns? → A: Decided at the purge, in this order: (1) with **exactly one other active member**, that member, **whatever their role** — a viewer becomes owner directly; (2) with more, **the member the person chose**, if still an active member; (3) else the **oldest editor** (earliest joined; ties by member id); (4) else the **oldest viewer**. Only a wallet with **no other active member** is deleted with the account. Ownership moves through the existing ownership transfer, so the plan-demotion rule applies under the new owner's plan and nobody is removed. *Assumed default.* (FR-030–FR-034)
- Q: How does the person choose, and when? → A: The deletion request gains an optional **`successors`** list of `{wallet_id, member_user_id}`, accepted when requesting and when repeating the request during the grace period (password again; the date never moves; a repeated request with `successors` replaces the stored choices, one without leaves them). A new **`GET /account/deletion-request`** answers, at any time, the account's deletion state and, for each shared wallet the person owns, **who would become owner and why** (`only_member`, `chosen`, `oldest_editor`, `oldest_viewer`) and whether a choice is expected (more than one other member). Choices are dropped when the deletion is cancelled. *Assumed default.* (FR-035–FR-038)
- Q: Do the deletion request or cancel need `Idempotency-Key`? → A: **No.** The key is reserved for three money operations (api-conventions); both are already idempotent (a repeat returns the same date; cancelling with nothing pending changes nothing) and move no money. *Assumed default.* (FR-039)
- Q: What happens to the departing person's transactions, and their name? → A: Transactions they entered in a wallet that survives **stay, unchanged, with `created_by`**. Their **display name is erased first**, through the same path a profile save uses, publishing `ProfileUpdated` naming `display_name`, so every wallet feed re-stamps their memberships with no name (B14a, ADR-0021) before the memberships are tombstoned. Activity entries keep `actor_user_id` with no name (already the rule for a non-member). *Assumed default.* (FR-040, FR-041)
- Q: A departing author's recurring rules in a surviving wallet (B7 open item)? → A: **They stay live and unchanged**, with `created_by` the departed id. Automatic confirmation already stops for an author who is no longer a member (spec 014 R-13, `auto_confirm_refusal: not_permitted` on the occurrence), so remaining members confirm or skip by hand, or end the rule. Transferring authorship would rewrite `created_by` and act with a new person's authority they never gave; ending the rule would remove a schedule others may rely on. *Assumed default.* (FR-042)
- Q: What is removed, what is kept? → A: **Removed**: address, display name, password, sessions, profile, budget start days, budgets, the person's categories not used by any remaining record, exports, pending invitations they sent (revoked first, through Sharing, before the key goes), their memberships (tombstoned first, then removed by the 90-day tombstone purge), solo wallets (tombstoned with everything in them, then purged at 90 days), and the per-account data key. **Kept, carrying identifiers only**: transactions, rules and occurrences in surviving wallets (with `created_by`), the person's categories **still used** by such records (archived, so the history keeps its labels for the other members, and deleted once nothing uses them), activity entries (90 days), audit entries (400 days), redeem codes (`redeemed_by`, 400 days), subscriptions (ended, identifiers and dates only, 400 days). *Assumed default.* (FR-043–FR-050)
- Q: How is the purge made safe to restart? → A: A scheduled run (hourly) picks every account whose grace period has ended and starts **one purge per account**. The first step moves the account to a new state **`purging`** (from then on it cannot be cancelled and sign-in answers as for any wrong credential) and ends every session; each later step is idempotent and records its completion, so a crashed or retried purge resumes where it stopped and a repeated step changes nothing. The last step strips the account row to a **husk** — id, state `purged`, the purge instant and its counters; no address, lookup value, password or key — which spec 007's `purged` state and spec 021's "Deleted" account page already expect. The husk is deleted 400 days after the purge, with the audit horizon. *Assumed default.* (FR-049, FR-051–FR-056)
- Q: What mail? → A: Spec 007's **request confirmation** gains the Google Play sentence and an export reminder; a new **reminder 3 days before** the purge ("sign in to cancel, export your data first"); and a **completion mail** sent by the purge before the data key is destroyed. A **"your export is ready"** mail. All bilingual (P-14), in the profile's language, never naming a wallet, amount or another person. *Assumed default.* (FR-057–FR-060)
- Q: What do operators see? → A: **Counts and states only.** Spec 021 already shows "Deletion pending · date" and "Deleted" and counts `pending_deletion` on the overview; this feature adds the `purging` state ("Deleting") to the account page and keeps it out of the overview's live-account counts. No wallet names, successors or export contents. *Assumed default.* (FR-061, FR-062)

### Session 2026-10-06 (clarify pass)

- Q: Does an **archived** wallet the departing person owns follow the same transfer rule as an active one? → A: **Yes.** "Live" means not tombstoned; archived wallets with other active members transfer, archived solo wallets are deleted. Archiving is a display state and does not reduce anyone's history. *Assumed default.* (FR-030)
- Q: What happens when a purge keeps failing? → A: The job retries with backoff and the hourly run resumes it; when an account has been `purging` for more than **24 hours**, Admin raises **one operator notification** (kind `account_purge_stalled`, identifiers only) so an operator can look at the logs. The account is never returned to `active`. *Assumed default.* (FR-063)
- Q: Does a failed export count toward the 5-per-24-hours budget? → A: **Yes** — the budget counts accepted requests, whatever their outcome, so it stays a simple bound on work; a failed export is not active and never blocks a new request. *Assumed default.* (FR-018)
- Q: Is there a size or time cap on an export? → A: **No content cap** (everything the person may see is included); the job has a **15-minute** run limit and the 60-minute stale rule (FR-019) closes anything stuck. *Assumed default.* (FR-011, FR-019)
- Q: Two members of the same wallet are both pending deletion — what happens? → A: Purges run per account in order of scheduled instant; each sees the result of the earlier one. If A owned a wallet whose only other member B is also pending, B becomes owner at A's purge (a pending member is still a member; FR-031 rule 1 does not skip), and at B's purge the wallet has no other member and is deleted. *Assumed default.* (Edge Cases)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan:

- **Whose authority the purge uses.** Wallet, membership, invitation and budget acts run through the existing services **as the departing person**, in the order that keeps their role valid (invitations revoked while they still hold it; ownership transferred while they are still owner; then they leave as an editor). Those acts therefore carry the person's id as actor in activity and audit entries, exactly as if they had done them by hand. Acts no user could do (ending sessions at the start, ending subscriptions, the completion entry) are recorded with actor `system`.
- **Subscriptions.** Billing gains a system-only command that ends an account's granting `manual` and `instapay` subscriptions at the purge; `google_play` rows are left to lapse on their own (no server-side cancel exists; open question 4).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Take a copy of everything (Priority: P1)

A person asks the app for an export of their whole account. The app shows "preparing"; a minute later it shows "ready" and a download button, and a mail arrives saying the same. They open the archive on a laptop: the transactions open in a spreadsheet program, and the structured files hold their wallets, categories, budgets, recurring rules and profile.

**Why this priority**: "take a copy first" is the first promise of the privacy page's "Leaving" section, and the store expects it beside deletion.

**Independent Test**: as a person with two wallets (one shared), request an account export; run the queue; the export is `ready`, the status read carries a download link; the archive contains `manifest.json`, the JSON files and `transactions.csv` with every transaction of both wallets, and no other member's name, address or id.

**Acceptance Scenarios**:

1. **Given** a signed-in person with no active export, **When** they request `scope: account`, **Then** it is `202` with an export `queued`, one `export_requested` audit entry is written, and a job is queued.
2. **Given** a queued export, **When** the job runs, **Then** the export becomes `ready` with its size and `expires_at` 24 hours later, the archive is on the private disk, and one "ready" mail is queued in the profile's language.
3. **Given** a ready export, **When** the person reads it, **Then** the answer carries a `download_url` valid 15 minutes and `download_url_expires_at`; **when** they follow it, **Then** the archive downloads as `application/zip` and, the first time only, one `export_delivered` audit entry is written.
4. **Given** a shared wallet where another member entered transactions, **When** it is exported, **Then** those transactions are included with `author: "other_member"` and no member's name, address or identifier appears anywhere in the archive.
5. **Given** an active export, **When** a second is requested, **Then** it is `409 EXPORT_IN_PROGRESS` with the active export as `current`, and nothing new is queued or audited.
6. **Given** 5 accepted requests in the last 24 hours, **When** a sixth is made, **Then** it is `429 RATE_LIMITED` with `Retry-After`.
7. **Given** a ready export 24 hours old, **When** the expiry run passes, **Then** the file is gone, the export reads `expired` with no link, and an old link answers not found.
8. **Given** an account in the deletion grace period, **When** an export is requested, **Then** it is served, and the scheduled deletion date does not move.

---

### User Story 2 - Export one wallet (Priority: P2)

A person who keeps a shared household wallet wants its history in a spreadsheet. They export just that wallet.

**Why this priority**: the brief and C15 ask for it; it is the account export narrowed to one wallet.

**Independent Test**: request `scope: wallet` for a wallet the person is a viewer of; the archive holds only that wallet's records; a wallet they are not a member of is `404`.

**Acceptance Scenarios**:

1. **Given** an active membership (any role), **When** they request a wallet export, **Then** it is `202` and the archive contains that wallet, its transactions (JSON and CSV), rules, occurrences, and the category names its entries use as the person sees them — no profile, budgets or other wallets.
2. **Given** a wallet they are not an active member of, or a deleted wallet, **When** requested, **Then** it is `404 NOT_FOUND` and nothing is queued or audited.
3. **Given** a `wallet` scope without `wallet_id`, or an `account` scope with one, **When** requested, **Then** it is `422 VALIDATION_FAILED` at `/wallet_id`.

---

### User Story 3 - Choose who keeps my shared wallets (Priority: P1)

Before deleting their account, a person who owns a wallet shared with two other people is asked who should become its owner. They pick one; the deletion is requested with that choice. They can see, at any time during the grace period, what will happen to each wallet they own.

**Why this priority**: the owner's decision of 2026-09-25 — shared history is never destroyed by one member leaving — depends on it, and C15 builds the step.

**Independent Test**: owner O with wallets A (one other member, a viewer) and B (an editor and a viewer); read the plan (`A → viewer, only_member`; `B → oldest editor, oldest_editor, choice expected`); request deletion choosing the viewer for B; read again (`B → viewer, chosen`).

**Acceptance Scenarios**:

1. **Given** an active account, **When** it reads `GET /account/deletion-request`, **Then** it is `200` with `state: active`, no dates, and for each owned wallet with other active members the would-be owner and the reason.
2. **Given** owned wallets, **When** deletion is requested with `successors` naming an active member of an owned wallet, **Then** it is `200` with the schedule and the resolved successor list showing `reason: chosen` for that wallet.
3. **Given** a `successors` entry naming a wallet the person does not own (or not shared, or deleted), the person themselves, a non-member, or a wallet twice, **When** deletion is requested, **Then** it is `422 VALIDATION_FAILED` with a pointer to the offending entry and nothing changes (the account does not enter the grace period).
4. **Given** a pending deletion, **When** the person repeats the request with the password and new `successors`, **Then** the stored choices are replaced and the date does not move; without `successors`, the choices are left as they were.
5. **Given** a pending deletion with choices, **When** it is cancelled (explicitly or by sign-in), **Then** the choices are dropped.

---

### User Story 4 - The account is deleted when the grace period ends (Priority: P1)

Thirty days after asking, the person's account is deleted. Their partner opens the app: the shared wallet the person owned now shows the partner as owner, last month's transactions are all there with the same balance, and the person who left shows as "Former member" with no name. The person receives a mail confirming the deletion; signing in with the old address no longer works.

**Why this priority**: it is what the request promised and what the privacy page states; nothing else in this feature matters if the purge does not happen or destroys someone else's history.

**Independent Test**: create D (owner of shared wallet W with partner P as viewer, owner of solo wallet S, member of P's wallet Q with transactions, categories, a budget, a pending invitation, two sessions, an export); request deletion; travel 30 days; run the purge. W is owned by P with every transaction intact; S is a tombstone; D's membership of Q is a tombstone and D's transactions in Q remain with `created_by = D`; D's profile name was erased (ProfileUpdated published) before the tombstones; the invitation is revoked; D's sessions, profile, budgets, unused categories, export and data key are gone, and the `users` row is a `purged` husk with no address, lookup value or password; one `deletion_completed` audit entry exists; a completion mail was sent.

**Acceptance Scenarios**:

1. **Given** an account pending deletion whose scheduled instant has passed, **When** the scheduled run passes, **Then** a purge starts for it, the account becomes `purging`, and every session ends on its next request.
2. **Given** a shared wallet with exactly one other active member who is a **viewer**, **When** the purge runs, **Then** that member becomes owner (the departing person never holds `editor` afterwards: their membership is tombstoned), and the wallet's transactions, balance and history are unchanged.
3. **Given** a shared wallet with several other members and a stored choice still an active member, **Then** the chosen member becomes owner; **given** the choice left the wallet in the meantime, or no choice, **Then** the oldest editor does; **given** no editor, **Then** the oldest viewer does.
4. **Given** a wallet with no other active member, **When** the purge runs, **Then** the wallet is deleted (tombstoned with everything in it, as an owner's delete does).
5. **Given** transactions the departing person entered in a surviving wallet, **When** the purge completes, **Then** they are still there with the same amounts and `created_by`, and the wallet's balance is unchanged.
6. **Given** the departing person's display name, **When** the purge runs, **Then** it is erased and `ProfileUpdated` naming `display_name` is published **before** any membership of theirs is tombstoned, so partners' copies re-stamp to no name.
7. **Given** pending invitations the person sent, **When** the purge runs, **Then** each is revoked through Sharing (address erased) before the data key is destroyed.
8. **Given** the purge has completed, **When** anyone signs in with the old address and password, **Then** it is `401 INVALID_CREDENTIALS`; **when** someone registers with that address, **Then** a fresh account is created.
9. **Given** an account in `purging`, **When** a sign-in or a cancel arrives, **Then** sign-in answers `401 INVALID_CREDENTIALS` and cancel, made with a session that has already ended, answers `401 UNAUTHENTICATED`; the purge continues.
10. **Given** a recurring rule the person authored in a surviving wallet with `auto_confirm: true`, **When** the purge completes, **Then** the rule is unchanged and its next due occurrence is not auto-confirmed but shows the refusal, for the members to confirm by hand.

---

### User Story 5 - A purge that stops halfway finishes correctly (Priority: P1)

The server restarts in the middle of a purge. When the scheduled run passes again, the purge resumes from the step it was on and completes; nothing is done twice and nothing is left behind.

**Why this priority**: a half-done deletion is the worst state: identity partly gone, records orphaned, or a wallet transferred twice. The brief requires idempotent, resumable deletion.

**Independent Test**: inject a failure after the ownership step; run again; the transfer is not repeated (one activity entry, one audit entry for it), the remaining steps complete, and every invariant of Story 4 holds. Run the purge a third time on the purged account: nothing happens.

**Acceptance Scenarios**:

1. **Given** a purge that failed after step N, **When** it runs again, **Then** steps 1..N are skipped or no-ops and steps N+1.. complete.
2. **Given** two purge runs started for the same account at once, **When** both run, **Then** exactly one does the work; the other ends without effect.
3. **Given** a completed purge, **When** the scheduled run passes again, **Then** nothing is selected for that account and no entry or mail is produced.
4. **Given** each step, **When** it is applied twice, **Then** the second application changes nothing (no extra `version`, `change_seq`, audit, activity, mail or event).

---

### User Story 6 - Reminders and honest mail (Priority: P2)

Three days before the deletion, the person gets a reminder that says how to cancel and to export first, and that a Google Play subscription is not cancelled by deleting the account. After the deletion, a last mail confirms it.

**Why this priority**: an irreversible act deserves one last warning, and the Play limitation must be said where the person will read it.

**Independent Test**: request deletion; travel 27 days; run the reminder twice — one mail; complete the purge — one completion mail, sent before the key is destroyed.

**Acceptance Scenarios**:

1. **Given** a pending deletion whose purge is 3 days or less away and no reminder sent, **When** the daily reminder run passes, **Then** one reminder mail is sent in the profile's language; a second run sends none.
2. **Given** a request confirmation mail (spec 007), **When** sent, **Then** it also states that a Google Play subscription must be cancelled in Google Play and suggests taking an export.
3. **Given** a purge, **When** it reaches the mail step, **Then** one completion mail is sent to the account's address before the address and the key are destroyed; a resumed purge does not send it twice.

---

### User Story 7 - Operators see that an account is leaving (Priority: P3)

An operator looking at an account in the back office sees "Deletion pending · 12 Nov", "Deleting" or "Deleted", and the overview counts accounts pending deletion. Nothing about the person's wallets or export is shown.

**Why this priority**: support needs to answer "why can't I find this account", but the operator boundary (counts, never content) must hold.

**Independent Test**: open the account page of a pending account — state and date shown, one `account_viewed` entry as today; overview shows the pending-deletion count (floor applied).

**Acceptance Scenarios**:

1. **Given** a pending account, **When** an operator opens its page, **Then** it shows the state and scheduled date, and no successor, wallet name or export detail.
2. **Given** a purged account, **When** looked up by id, **Then** the page shows "Deleted" and the id only; looked up by its former address, it is not found (`account_lookup_missed`), because the lookup value is gone.
3. **Given** the overview, **When** opened, **Then** it shows the number of accounts pending deletion, as "fewer than 10" below the anonymity floor.

---

### Edge Cases

- **Export of an empty account.** Served; the JSON files hold empty lists and the CSV has its header only.
- **Export job fails** (disk full, crash). Retried up to 3 times; then `failed` with `failure_code: generation_failed`; no mail; the person may request again at once (the failure does not count as active).
- **A ready export and a new request.** A ready export is not active; a new request is accepted and the older ready export stays until its own expiry.
- **Account purged with an export in flight.** The purge deletes every export file and row; a queued job finds no export and ends without effect.
- **Download link used after the export expired or the account was purged.** Not found; nothing recorded.
- **Wallet deleted between request and generation.** A wallet export whose wallet is no longer readable at generation time fails with `failure_code: wallet_unavailable`.
- **Membership lost between request and generation.** The account export covers the wallets readable when the job runs.
- **Chosen successor leaves, is removed, or starts their own deletion.** At purge time the choice is honoured only if they are still an active member; otherwise the fallback order applies. A candidate whose own account is pending deletion or purging is skipped by the fallback unless no other candidate exists.
- **Two members of one wallet deleting at once.** Purges run per account in order of scheduled instant and lock in the established order (wallets ascending, then users ascending); the second sees the first's result. A pending member who is the only other member becomes owner at the first purge, and the wallet is deleted at their own purge if nobody else remains.
- **Ownership transfer to a free-plan member.** The new owner's plan governs (P-09 rule 4); members beyond its quota become viewers by the existing demotion rule; nobody is removed.
- **The departing person's solo wallet is the counter wallet of a transfer recorded in a shared wallet.** The solo wallet's deletion behaves exactly as when its owner deletes it by hand (specs 010 and 012): the shared wallet's record of the transfer and its balance are unchanged.
- **Categories of the departing person used by surviving records.** Kept and archived with the departed id as owner, so partners keep the label; deleted by the purge sweep once nothing references them.
- **A deletion requested by an unverified account.** Allowed (spec 007); the purge is the same.
- **Purge due while the queue is down.** The scheduled run selects it again next hour; nothing is purged twice.
- **Sign-in during the reminder window.** Cancels the deletion (spec 007); no further reminder.
- **Activity entries and audit entries** keep the departed id; operator audit filters by that id still work for 400 days.
- **Redeem codes** keep `redeemed_by` until the claim's 400-day delete; subscriptions created from them are ended at the purge like any other.

## Requirements *(mandatory)*

### Functional Requirements

**Export — content**

- **FR-001**: An export MUST be a single ZIP archive containing `manifest.json` (format version `1`, `generated_at`, `scope`, `wallet_id` or null, and a count per file), one JSON file per record kind, and `transactions.csv`.
- **FR-002**: An account export MUST contain: `account.json` (address, verification instant, created instant, account state and, while pending, the scheduled deletion instant), `profile.json` (display name, locale, timezone, default currency, budget start day) with `budget_start_days`, `devices.json` (device sessions: name, platform, app version, state, signed-in, last-used and ended instants — never a credential, hash or installation identifier), `subscriptions.json` (plan code and name, provider, status, period instants), `categories.json` (the person's own, live and tombstoned), `budgets.json` (category, currency, limit, period type, `archived_at`; tombstones as tombstones; no progress), `memberships.json` (wallet id, own role, joined instant, state), `invitations.json` (invitations the person sent: wallet id, channel, role, state, instants — **never** the invited address or token), and the wallet files of FR-003 for every wallet with an active membership. It MUST NOT contain activity entries (90-day operational history, readable in the app), audit entries (operator-only), payment claims (they hold no account) or redeem codes (their effect is the subscription).
- **FR-003**: Per wallet the export MUST contain the wallet (name, type, currency, opening balance, archived instant, the person's role, member count), its transactions (`wallets.json`, `transactions.json`), recurring rules with template, schedule, `timezone` and author marker (`recurring_rules.json`), and occurrences with key, status, effective values and `transaction_id` (`occurrences.json`). Internal columns (`auto_confirm_from`, `change_seq`, `member_change_seq`, `purged_change_seq`, postings) MUST NOT appear. Tombstoned records still within retention MUST appear as tombstones (`id`, `deleted_at`, and the scope keys only).
- **FR-004**: No export MUST contain another person's name, address, identifier, category list or budget. Authorship MUST be expressed as `author: "me" | "other_member"`; a category on another member's entry MUST be shown by the name the person already sees through the wallet's category references.
- **FR-005**: `transactions.csv` MUST be UTF-8 with a byte-order mark, comma-separated with RFC 4180 quoting and CRLF line ends, with one header row: `date, wallet, wallet_id, kind, amount_minor, currency, category, counter_wallet, description, memo, counterparty, cleared, author, transaction_id, created_at, updated_at`. Amounts MUST be integer minor units with their currency (P-03); no decimal string. A text cell beginning with `=`, `+`, `-`, `@`, tab or carriage return MUST be prefixed with `'` so a spreadsheet does not evaluate it. `wallet` and `category` are the names the person sees; `counter_wallet` is the counter wallet's name when the person is a member of it, otherwise empty. Tombstoned transactions MUST NOT appear in the CSV.
- **FR-006**: Every instant MUST be UTC ISO 8601 and every calendar date `YYYY-MM-DD` (P-12); numerals Western (P-14).
- **FR-007**: A wallet export MUST contain `manifest.json`, that wallet's files of FR-003, `categories.json` holding only the category names its entries use as the person sees them, and `transactions.csv` for that wallet; nothing user-scoped.

**Export — request, production, delivery**

- **FR-008**: A signed-in person MUST be able to request an export of `scope: account` or `scope: wallet` (with `wallet_id`). A wallet the person is not an active member of, or a deleted one, MUST be `404 NOT_FOUND`. A mismatch between `scope` and `wallet_id` MUST be `422 VALIDATION_FAILED` at `/wallet_id`.
- **FR-009**: Export MUST NOT be gated by an entitlement or by address verification, and MUST be served during the deletion grace period without moving the scheduled instant.
- **FR-010**: An accepted request MUST answer `202` with the export (`queued`), record exactly one `export_requested` audit entry, and queue its production after commit.
- **FR-011**: The production job MUST move the export `queued` → `running` → `ready` (with `size_bytes`, `completed_at` and `expires_at` = completed + 24 hours) or `failed` (with a `failure_code`), MUST be safe to retry (a retry rebuilds the archive from scratch under the same name), MUST stop after 15 minutes of running, MUST abandon its work (leaving no file) when the export row is gone or the account has left `active`/`pending_deletion`, MUST NOT log any record content (P-15 rule 4), and MUST read data only through other modules' application query services (P-02).
- **FR-012**: The archive MUST be written to the private `local` disk under a server-chosen path and MUST NOT be reachable without a valid signature.
- **FR-013**: A read of a `ready` export MUST carry a `download_url` signed and valid for 15 minutes (never past `expires_at`) and its `download_url_expires_at`; any other state MUST carry neither.
- **FR-014**: The signed download route MUST serve the archive as `application/zip` with an attachment file name, MUST answer not-found for an unknown or expired export, a bad or expired signature, or an account that is no longer `active` or `pending_deletion`, and MUST record `export_delivered` exactly once per export (the first successful download).
- **FR-015**: When an export becomes `ready`, exactly one mail MUST be queued to the account's address in the profile's language, carrying a signed link valid until `expires_at`. No mail on failure.
- **FR-016**: A daily-or-more-frequent run MUST delete the files of exports past `expires_at` and mark them `expired`, and MUST delete export records 30 days after they were requested.
- **FR-017**: While a person has an export `queued` or `running`, a further request MUST be `409 EXPORT_IN_PROGRESS` carrying that export as `current`, and MUST NOT be queued, audited or counted.
- **FR-018**: Accepted requests, whatever their outcome, MUST be limited to 5 per 24 hours per person (`429 RATE_LIMITED`, `Retry-After`); the signed download route to 20 per 10 minutes per origin. Both budgets MUST be added to the baseline's route budgets.
- **FR-019**: An export `queued` or `running` for more than 60 minutes MUST be marked `failed` (`failure_code: timed_out`) by the expiry run.
- **FR-020**: The baseline's protected-attributes table MUST gain a row for the export archive: no encryption at rest, private disk, reachable only by a signed link (15 minutes in the app, until expiry by mail), deleted 24 hours after it is ready and at the purge; never logged.
- **FR-021**: `export_requested` (detail `{scope}`) and `export_delivered` (detail `{export_id}`) MUST be written by Identity, subject the user, and MUST carry nothing from the exclusion list.
- **FR-022**: A person MUST be able to list their exports newest first (cursor-paged) and read one by id; another person's export id MUST be `404`.

**Deletion — ownership choice**

- **FR-030**: At the purge, each live wallet (not tombstoned; archived included) the departing person owns MUST be resolved: no other active member → deleted with the account; otherwise ownership transfers to exactly one successor (FR-031) and the wallet is never deleted.
- **FR-031**: The successor MUST be: (1) the only other active member, whatever their role; else (2) the person's stored choice for that wallet, if still an active member; else (3) the active editor with the earliest membership (ties by member id); else (4) the active viewer with the earliest membership. "Earliest membership" is the creation instant of the current membership record; a member who left and re-joined starts again from the re-join. In steps 3 and 4, a candidate whose own account is pending deletion or purging MUST be skipped unless no other candidate exists.
- **FR-032**: The transfer MUST use the existing ownership transfer, so its activity entry, audit entry (`role_changed`), feed stamps and plan-demotion rule apply; a viewer successor MUST become `owner` directly.
- **FR-033**: The successor resolution MUST be one domain rule, used both by the purge and by the read of FR-036, so the preview and the act cannot differ for the same state.
- **FR-034**: The baseline's "Leaving a shared wallet" table and the privacy commitments' "Leaving" section MUST be amended in both languages, with their mapping rows, in the same change: owned shared wallets pass to another member; only wallets nobody else belongs to are removed.
- **FR-035**: The deletion request MUST accept an optional `successors` list (0–100 entries) of `{wallet_id, member_user_id}`. Every wallet named MUST be a live wallet the person owns with at least one other active member, the member MUST be an active member of it other than the person, and a wallet MUST appear at most once; otherwise `422 VALIDATION_FAILED` with a pointer to the entry, and nothing changes.
- **FR-036**: `GET /account/deletion-request` MUST answer the account's deletion state (`active` | `pending_deletion`), the requested and scheduled instants when pending, and for each live wallet the person owns with at least one other active member: `wallet_id`, `successor_user_id`, `reason` (`only_member` | `chosen` | `oldest_editor` | `oldest_viewer`) and `choice_expected` (true when there is more than one other active member). It MUST NOT carry names.
- **FR-037**: The deletion request's answer MUST gain the same `successors` list (additive; spec 007's members unchanged).
- **FR-038**: Repeating the request during the grace period MUST keep the scheduled instant; with `successors` present it MUST replace the stored choices, without it leave them. Cancelling MUST drop them.
- **FR-039**: The deletion request and cancel MUST NOT require or store `Idempotency-Key`.

**Deletion — the purge**

- **FR-040**: The purge MUST erase the display name through Identity's profile path and publish `ProfileUpdated` with `display_name` in `changedFields` before any membership of the person is tombstoned.
- **FR-041**: Transactions, recurring rules and occurrences the person authored in surviving wallets MUST be kept unchanged, `created_by` included. No ledger row is deleted or rewritten, and no balance changes.
- **FR-042**: Recurring rules the person authored in surviving wallets MUST stay live and unchanged; automatic confirmation stays refused for them by spec 014's rule.
- **FR-043**: Pending invitations the person sent MUST be revoked through Sharing's application layer before the data key is destroyed.
- **FR-044**: Every remaining active membership of the person (after transfers and solo-wallet deletions) MUST be tombstoned, stamped from the wallet's counter and from the person's user counter (ADR-0017), with an activity entry and a `membership_revoked` audit entry, exactly as leaving does.
- **FR-045**: Wallets with no other active member MUST be deleted through the existing wallet deletion (tombstones, participants, `WalletDeleted`).
- **FR-046**: Budgets MUST be removed explicitly by Budgeting and the person's categories not referenced by any record MUST be removed by Wallets — each first **tombstoned** (`deleted_at`, `version + 1`, `change_seq` from the person's user counter, P-07 rule 5) and then removed in the same purge, raising the user scope's purge horizon, because no reader of that user scope remains (ADR-0024 supersedes ADR-0008 rule 12's 90-day minimum for the user scope of a purged account only; wallet-scope tombstones keep their 90 days); categories still referenced (including only by tombstones of a deleted solo wallet) MUST be archived and kept with their owner id until nothing references them, then deleted by the tombstone purge run.
- **FR-047**: Every granting `manual` or `instapay` subscription of the account MUST be ended through Billing's application service at the purge; a `google_play` subscription MUST NOT be changed (it cannot be cancelled server-side and lapses through the provider's own flow); every subscription row of the account MUST be stamped with the purge instant, kept with identifiers and dates only, and deleted 400 days after the purge.
- **FR-048**: Every export file and record of the person MUST be deleted.
- **FR-049**: Device sessions and every credential MUST be ended at the first step with end reason `account_deleted` (each recorded `session_revoked`, actor `system`); Laravel `sessions` rows for the user MUST be deleted; the profile MUST be tombstoned and then removed (as FR-046), budget start days and identity links (reset and verification links) MUST be deleted, and the sign-in failure counter keyed by the account's lookup value MUST be deleted before that value is cleared; the `user_keys` row MUST be deleted (key destruction); last, the `users` row MUST be reduced to a husk (`state = purged`, `purged_at`, counters; address ciphertext, lookup value and password hash cleared) so the address can register again. The husk MUST be deleted 400 days after `purged_at`.
- **FR-050**: Audit entries, activity entries, category references, operator notifications, payment claims and redeem codes MUST NOT be edited or deleted by the purge; they keep the person's id until their own retention ends.
- **FR-051**: A scheduled run, at least hourly, MUST start a purge for every account in `pending_deletion` whose scheduled instant has passed, and resume every account in `purging`.
- **FR-052**: The first step MUST, under the account row's lock, check the account is still `pending_deletion` and due, and move it to `purging`; an account not in that state MUST be left alone. From `purging`, cancellation MUST NOT be possible and sign-in MUST answer `401 INVALID_CREDENTIALS`.
- **FR-053**: Each purge step MUST be idempotent and its completion recorded, so a retried or resumed purge continues from the first incomplete step, including after a crash between a step's commit and the record of its completion (the step re-runs as a no-op); at most one purge MUST run per account at a time.
- **FR-054**: Wallet locks MUST follow the established order (wallets ascending, then users ascending, ADR-0017 rule 5); each wallet's transfer, deletion or leaving MUST be its own unit of work.
- **FR-055**: Completion MUST record exactly one `deletion_completed` audit entry (actor `system`, subject the user, detail `{wallets_transferred, wallets_deleted, memberships_ended}` as counts).
- **FR-056**: Nothing written by the purge or the export (logs, audit, activity, events) MUST contain the address, display name, wallet or category names, amounts or notes; a queued mail job that carries the address as its recipient MUST be encrypted at rest in the queue, and the completion mail MUST NOT be queued at all (FR-059).

**Mail**

- **FR-057**: Spec 007's deletion confirmation mail MUST also say that a Google Play subscription is not cancelled by deleting the account and must be cancelled in Google Play, and suggest taking an export first.
- **FR-058**: A reminder MUST be sent once, when the purge is 3 days or less away, stating the date, that signing in or cancelling stops it, the export suggestion and the Google Play sentence.
- **FR-059**: The purge MUST send one completion mail to the account's address before the address and key are destroyed, sent within the purge step (not left queued with the address in it), and never twice.
- **FR-060**: Every mail of this feature MUST be in Arabic and English (P-14), in the profile's language, and MUST NOT name a wallet, amount or another person.

**Operators**

- **FR-061**: The back-office account page MUST show the deletion state (spec 021's "Deletion pending" and "Deleted", plus "Deleting" for `purging`), and nothing about successors, wallets or exports.
- **FR-062**: The back-office overview MUST show the count of accounts pending deletion, under the existing anonymity floor.
- **FR-064**: The purge MUST act through other modules' application services with the departing person as actor for acts their role permits, ordered so the role is still valid when each act runs, and as `system` otherwise; no module's tables MUST be written by Identity directly (P-02).
- **FR-063**: An account `purging` for more than 24 hours MUST raise exactly one operator notification of kind `account_purge_stalled` carrying the user id and the instant only.

**Documents and contract**

- **FR-070**: The API MUST be frozen in `docs/openapi/paths/exports.yaml` (new) and `docs/openapi/paths/identity.yaml` (deletion request), indexed from `openapi.yaml`, with examples, and `info.version` bumped v1.12 → v1.13. No new error code (FR-017 reuses `EXPORT_IN_PROGRESS`; its catalogue meaning gains the `current` sentence).
- **FR-071**: The glossary MUST gain **Export** and **Successor** (and the `purging` state on User) before the terms are used in code; the baseline gains the retention rows (export archive 24 hours, export record 30 days, ended subscriptions 400 days after purge), the route budgets, the protected-attribute row and the amended Leaving table; ADR-0024 records the deletion decisions (successor order, the departed id as the marker, the `purging` state, the husk, and the immediate removal of a purged user scope's tombstones, superseding ADR-0008 rule 12 for that case only).
- **FR-072**: No new dependency.

### Key Entities

- **Export** (Identity): one requested copy — person, scope (`account` | `wallet`), wallet id, status (`queued` | `running` | `ready` | `failed` | `expired`), failure code, requested/started/completed/expires instants, size, private file path, first-delivered instant. Not syncable; read through the export endpoints.
- **Successor** (Identity, value): the member who will own a departing person's shared wallet, with the reason; the person's stored **choices** are held per (user, wallet) while the deletion is pending.
- **Account purge progress** (Identity, operational): per account in `purging`, the last completed step and its start instant.
- **User** (existing): gains the `purging` state and, once `purged`, is a husk holding no identity attribute.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Make leaving complete and honest: a person can take a full copy of what they entered, and when they delete their account it is actually deleted — identity, credentials, private records and the key that decrypts them — while the wallets they shared keep their history and a new owner.

### 2. Glossary terms

[User](../../docs/glossary.md#user) (states), [Profile](../../docs/glossary.md#profile), [Device Session](../../docs/glossary.md#device-session), [Audit Entry](../../docs/glossary.md#audit-entry), [Wallet](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member) (ownership transfer, plan demotion), [Activity Entry](../../docs/glossary.md#activity-entry), [Category](../../docs/glossary.md#category), [Category Reference](../../docs/glossary.md#category-reference), [Transaction](../../docs/glossary.md#transaction), [Recurring Rule](../../docs/glossary.md#recurring-rule), [Occurrence](../../docs/glossary.md#occurrence), [Budget](../../docs/glossary.md#budget), [Invitation](../../docs/glossary.md#invitation), [Subscription](../../docs/glossary.md#subscription), [Redeem Code](../../docs/glossary.md#redeem-code), [Purge Horizon](../../docs/glossary.md#purge-horizon). **Added in this change** (before use in code): **Export**, **Successor**; User gains `purging` (`active` ⇄ `pending_deletion` → `purging` → `purged`, a husk deleted after 400 days); Transaction's note on `created_by` states that it outlives its author as an identifier that resolves to nobody ("Former member").

### 3. Data model

Owned by Identity (`app/Modules/Identity/Infrastructure/Database/Migrations`). Detail in [data-model.md](./data-model.md).

- `data_exports` — `id` (UUIDv7), `user_id` (FK → users, cascade), `scope`, `wallet_id` (no FK), `status`, `failure_code`, `file_path`, `size_bytes`, `requested_at`, `started_at`, `completed_at`, `expires_at`, `delivered_at`, timestamps; partial unique index on `user_id` where status in (`queued`, `running`).
- `deletion_successor_choices` — `user_id` (FK → users, cascade), `wallet_id`, `member_user_id`, `chosen_at`; primary key (`user_id`, `wallet_id`).
- `account_purges` — `user_id` (PK, FK → users, cascade), `started_at`, `last_step`, `attempts`, `counts` (integers only), `mail_sent_at`, `stall_notified_at`, `updated_at`.
- `users.state` CHECK gains `purging`; `users.deletion_reminded_at`, `users.purged_at` (nullable instants); `email_ciphertext`, `email_lookup` and `password_hash` become null in `purged`.
- `device_sessions.end_reason` CHECK gains `account_deleted`.
- Billing's `subscriptions` gains `ended_by_account_purge_at` (nullable instant) for the 400-day sweep.
- Identity's audit `subject_type` is unchanged (`user`).

No float or decimal column (P-03).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| An export never contains another person's name, address, id, categories or budgets | Baseline Export rule 3; FR-004 | `tests/Feature/Identity/Export/ExportContentTest.php` |
| At most one active export per person; a second is 409 with `current` | Baseline Export rule 1; FR-017 | `tests/Feature/Identity/Export/RequestExportTest.php`, `tests/Contract/Identity/ExportsTest.php` |
| Export never moves the deletion date | Baseline Export rule 4; FR-009 | `tests/Feature/Identity/Export/RequestExportTest.php` |
| CSV amounts are integer minor units; formula cells neutralised | P-03; FR-005 | `tests/Unit/Identity/Export/TransactionsCsvTest.php` |
| `export_delivered` written once per export | Baseline; FR-014 | `tests/Feature/Identity/Export/DownloadExportTest.php` |
| Successor order: only member → chosen → oldest editor → oldest viewer | Owner decision; FR-031 | `tests/Unit/Identity/Deletion/SuccessorRuleTest.php` |
| A shared wallet is never deleted by its owner's purge | Owner decision; FR-030 | `tests/Feature/Identity/Deletion/PurgeOwnedWalletsTest.php` |
| No ledger row changes; balances identical before and after | P-04, P-05; FR-041 | `tests/Feature/Identity/Deletion/PurgeKeepsHistoryTest.php` |
| Name erased and `ProfileUpdated` published before any membership tombstone | B14a; FR-040 | `tests/Feature/Identity/Deletion/PurgeOrderTest.php` |
| Invitations revoked before key destruction | B9; FR-043 | `tests/Feature/Identity/Deletion/PurgeOrderTest.php` |
| Each step idempotent; resumed purge completes; one purge per account | Brief; FR-053 | `tests/Feature/Identity/Deletion/PurgeResumeTest.php` |
| From `purging`, no cancel and no sign-in | FR-052 | `tests/Feature/Identity/Deletion/PurgingStateTest.php` |
| After purge: husk only (no address, lookup, password), no profile, sessions, key, budgets, unused categories, exports; audit and activity untouched | Baseline Deletion rule 3; FR-046–FR-050 | `tests/Feature/Identity/Deletion/PurgeRemovesTest.php` |
| Exactly one `deletion_completed`; nothing excluded in any entry or log | Baseline; FR-055, FR-056 | `tests/Feature/Identity/Deletion/PurgeAuditTest.php` |
| Completion mail sent once, before key destruction | FR-059 | `tests/Feature/Identity/Deletion/PurgeMailTest.php` |
| Preview and purge use the same successor rule | FR-033 | `tests/Feature/Identity/Deletion/DeletionPlanTest.php` |

### 5. API

Under `/api/v1`. **Frozen for the C15 client** (contract first); `info.version` v1.12 → v1.13 (additive). New path file `docs/openapi/paths/exports.yaml` (tag `Identity`); `docs/openapi/paths/identity.yaml` changes additively.

| Method | Path | operationId | Success | Error codes (beyond the shared header refusals 400, 406, 415, 426, 500) |
|---|---|---|---|---|
| POST | `/exports` | `requestExport` | `202` Export | `UNAUTHENTICATED`, `MALFORMED_JSON`, `VALIDATION_FAILED`, `NOT_FOUND` (wallet), **`EXPORT_IN_PROGRESS`** (409, `current`), `RATE_LIMITED` |
| GET | `/exports` | `listExports` | `200` collection, newest first | `UNAUTHENTICATED`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER` |
| GET | `/exports/{id}` | `getExport` | `200` Export (with `download_url` when ready) | `UNAUTHENTICATED`, `INVALID_ID`, `NOT_FOUND` |
| GET | `/account/deletion-request` | `getAccountDeletionPlan` | `200` DeletionPlan | `UNAUTHENTICATED` |
| POST | `/account/deletion-request` | `requestAccountDeletion` (changed) | `200` DeletionSchedule + `successors` | existing + `VALIDATION_FAILED` pointers into `/successors/{n}` |
| DELETE | `/account/deletion-request` | `cancelAccountDeletion` (unchanged; drops choices) | `204` | unchanged |

Outside `/api/v1`: the signed download route `GET /exports/{id}/file?expires=…&signature=…` (no bearer, no client headers — a browser or share sheet opens it), answering `application/zip` or `404`. It is not an API operation and is not in OpenAPI; `download_url` documents it.

**Export** `{ id, scope: "account"|"wallet", wallet_id, status: "queued"|"running"|"ready"|"failed"|"expired", failure_code: null|"generation_failed"|"wallet_unavailable"|"timed_out", requested_at, completed_at, expires_at, size_bytes, download_url, download_url_expires_at }`.

**Export request** `{ scope, wallet_id? }`.

**DeletionPlan** `{ state: "active"|"pending_deletion", deletion_requested_at, deletion_scheduled_for, successors: [ { wallet_id, successor_user_id, reason: "only_member"|"chosen"|"oldest_editor"|"oldest_viewer", choice_expected } ] }`.

**Deletion request** `{ password, successors?: [ { wallet_id, member_user_id } ] }`; **DeletionSchedule** `{ state: "pending_deletion", deletion_scheduled_for, successors: [...] }`.

**Answers C15 needs** (frozen):

| C15 asks | Answer |
|---|---|
| Export job shape and states | Export resource above; `queued` → `running` → `ready` → `expired`, or `failed`. Poll `GET /exports/{id}` (suggested every 5 s while `queued`/`running`); open `download_url` in the system browser or share sheet; never store the file |
| The one-active-export error | `409 EXPORT_IN_PROGRESS`, body member `current` = the active export: show it instead of failing |
| Format | ZIP: JSON files + `transactions.csv`. "CSV/XLSX" becomes CSV only |
| Ownership-choice fields | `successors` on `POST /account/deletion-request`; `GET /account/deletion-request` lists each owned shared wallet with `successor_user_id`, `reason`, `choice_expected`. Ask only where `choice_expected` is true; candidates are the wallet's active members the app already holds |
| Fallback when the only other member is a viewer | That viewer becomes owner directly (`reason: only_member`) |
| Fallback with no editor | Oldest viewer (`reason: oldest_viewer`); never deleted |
| Play subscription notice | Deleting does not cancel Google Play; the app should say "cancel in Google Play" — the mails say it too |
| Gating | None: export is not behind `export.enabled` or the verification gate |
| Rate limit | `429 RATE_LIMITED` with `Retry-After`; 5 accepted exports per 24 h |

### 6. Authorization and entitlements

Exports: the person's own only (another's id is `404`); a wallet export needs an active membership of any role (the Wallets matrix's read row). No entitlement and no verification gate (P-09 rule 5; baseline C2). Deletion plan and request: the signed-in person, about wallets they own. The purge runs as `system`; it writes through other modules' application services, which apply their own invariants (P-02, P-08). The signed download route is authorised by its signature alone, scoped to one export, and expires.

### 7. Events

Published: `ProfileUpdated` (existing, spec 008/017) with `display_name` in `changedFields` from the purge's erase step; `WalletOwnershipTransferred`, `WalletDeleted`, `MemberRevoked`-equivalent membership events as the reused services already publish them. One new domain event, `AccountPurgeStalled` (Identity; event id, occurred_at, user id), consumed by an Admin queued listener that raises the `account_purge_stalled` notification (FR-063), as spec 007's cancellation notice does. Otherwise no new event: the purge orchestrates by calling each module's application service through an Identity-owned participant interface (each module registers its participant), and mails are sent by Identity. Consumed: `UserDeletionCancelled` (existing) — drops the successor choices (same module, may be synchronous).

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–8 account export | Unit, Feature, Contract | `tests/Unit/Identity/Export/TransactionsCsvTest.php`, `tests/Feature/Identity/Export/RequestExportTest.php`, `tests/Feature/Identity/Export/GenerateExportTest.php`, `tests/Feature/Identity/Export/ExportContentTest.php`, `tests/Feature/Identity/Export/DownloadExportTest.php`, `tests/Feature/Identity/Export/ExpireExportsTest.php`, `tests/Contract/Identity/ExportsTest.php` |
| US2 · 1–3 wallet export | Feature, Contract | `tests/Feature/Identity/Export/ExportContentTest.php`, `tests/Contract/Identity/ExportsTest.php` |
| US3 · 1–5 successor choice | Unit, Feature, Contract | `tests/Unit/Identity/Deletion/SuccessorRuleTest.php`, `tests/Feature/Identity/Deletion/DeletionPlanTest.php`, `tests/Contract/Identity/DeletionRequestTest.php` |
| US4 · 1–10 purge | Feature | `tests/Feature/Identity/Deletion/PurgeOwnedWalletsTest.php`, `PurgeKeepsHistoryTest.php`, `PurgeOrderTest.php`, `PurgeRemovesTest.php`, `PurgingStateTest.php`, `PurgeAuditTest.php` |
| US5 · 1–4 resume | Feature | `tests/Feature/Identity/Deletion/PurgeResumeTest.php` |
| US6 · 1–3 mail | Feature | `tests/Feature/Identity/Deletion/PurgeMailTest.php`, `tests/Feature/Identity/Deletion/DeletionReminderTest.php` |
| US7 · 1–3 operators | Feature | `tests/Feature/Admin/AccountDeletionStateTest.php` |

### 9. Open questions

1. **XLSX** — needs a spreadsheet dependency and owner approval; CSV meets the baseline's "spreadsheet-openable". Does not block.
2. **`export.enabled`** — exists in the plan catalogue (spec 009) but is not consulted, because export is a privacy right and P-09 rule 5 limits free only on sharing. Retire the flag or keep it for a future paid export format. Does not block.
3. **Decimal amounts in the CSV** — P-03 forbids decimal strings, so the CSV carries minor units; a display column would need a constitution clarification. Does not block.
4. **Google Play subscriptions** — B10 is not built; when it is, the purge should at least revoke the server-side record and the mail keeps telling the person to cancel in Play. Does not block.
5. **Mail to the new owner** — not sent; the new owner learns from the feed and the activity entry. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An account export of 10,000 transactions across 10 wallets is `ready` within 2 minutes of the request on the development stack.
- **SC-002**: In 100% of test archives, no other person's name, address or identifier appears (string search for every other member's id, name and address returns nothing).
- **SC-003**: `transactions.csv` opens in a common spreadsheet program with Arabic text intact and one row per live transaction.
- **SC-004**: 100% of accounts whose grace period ended are purged within 2 hours when no step fails (and resumed on the next hourly run when one does), and a purge interrupted at any step completes on its next run with the same end state as an uninterrupted one.
- **SC-005**: For every surviving shared wallet, balances and transaction counts are identical before and after the owner's purge, and exactly one active owner remains.
- **SC-006**: 0 log lines, audit entries, activity entries or queued payloads contain an address, display name, wallet or category name, amount or note from the purge or the export.
- **SC-007**: After the purge, the departed person's address signs in to nothing and registers as new; their data key no longer exists.
- **SC-008**: The C15 integration checkpoint passes: an export completes and its link opens on the phone; a deletion with a shared wallet asks for the new owner, and after the purge command the other member sees themselves as owner.

## Assumptions

- Spec 007's deletion request, cancel, `deletion_scheduled_for`, `UserDeletionRequested`/`UserDeletionCancelled`, audit service, keyring and `user_keys` are reused; spec 008's profile save and `ProfileUpdated`; spec 010's ownership transfer, wallet deletion with participants, membership leave and plan demotion; spec 013's tombstone purge; spec 014's auto-confirm refusal; spec 015's budgets; spec 016's invitation revoke; spec 009/020's subscriptions; spec 021's account page and overview.
- Queues, the scheduler, mail (Mailpit in dev), the rate limiter, signed routes, the private `local` disk and PHP's `zip` extension (present in the container) are already installed; no new dependency.
- The phone opens `download_url` in the system browser or share sheet; the signed route needs no client headers.
- Seeder: N/A — no reference data.
