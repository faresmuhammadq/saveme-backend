# Privacy, Retention & Deletion-Safety Requirements Checklist: Export & Data Deletion

**Purpose**: Unit tests for the requirements of spec 022 — export contents, purge order, restart safety, and the frozen contract
**Created**: 2026-10-06
**Feature**: [spec.md](../spec.md)

**Ownership note**: `[x]` means the reviewer judged the requirement-quality criterion satisfied, not that anything is built. Depth: standard; audience: PR reviewer; questions defaulted (owner away). The owner delegated review to the agent; each item's resolution is noted under Notes.

## Requirement Completeness

- [x] CHK001 - Does the spec list every user-scoped record kind that an account export contains, matched against every module's user-owned tables? [Completeness, Spec §FR-002, data-model]
- [x] CHK002 - Is every table holding the person's id assigned an explicit fate (removed, kept, anonymised, retention end) at the purge? [Completeness, Spec §FR-043–FR-050, data-model]
- [x] CHK003 - Are requirements defined for the framework session store and queued jobs that may hold the person's id or address? [Gap, Spec §FR-049, FR-056]
- [x] CHK004 - Are the mails of this feature each specified with trigger, recipient, language, link lifetime and forbidden content? [Completeness, Spec §FR-057–FR-060]
- [x] CHK005 - Is the fate of the departing person's sign-in failure counter and pending reset/verification links specified? [Completeness, Spec §FR-049]
- [x] CHK006 - Are the baseline, privacy-commitment and glossary amendments enumerated so a reviewer can check each one? [Completeness, Spec §FR-034, FR-071]

## Requirement Clarity

- [x] CHK007 - Is "what the person can already see" defined precisely enough to decide inclusion of other members' entries, category labels and tombstones? [Clarity, Spec §FR-003, FR-004]
- [x] CHK008 - Is "oldest editor" defined by a concrete attribute and tie-break, including for re-joined members? [Clarity, Spec §FR-031]
- [x] CHK009 - Is the "stable anonymous marker" defined unambiguously, and is it consistent with "created_by never changes"? [Clarity, Spec §Reconcile table, FR-041]
- [x] CHK010 - Are the CSV column semantics (wallet name vs id, counter wallet, category label) defined for entries whose wallet or category the person cannot see? [Clarity, Spec §FR-005]
- [x] CHK011 - Is "live wallet" defined (archived included or not) for the transfer rule? [Clarity, Spec §FR-030]

## Requirement Consistency

- [x] CHK012 - Is the purged account's representation consistent between spec 007's `purged` state, spec 021's account page and this spec's husk? [Consistency, Spec §FR-049, FR-061]
- [x] CHK013 - Is the B14 inherited note ("users row last", memberships tombstoned first) consistent with keeping a husk? [Consistency, Spec §Clarifications]
- [x] CHK014 - Do the export link lifetimes (15 minutes in-app, until expiry by mail, 24-hour file) agree across spec, contract and baseline amendment? [Consistency, Spec §FR-013–FR-016, FR-020]
- [x] CHK015 - Is the "no new domain event" statement consistent with `AccountPurgeStalled`? [Consistency, Spec §7]
- [x] CHK016 - Is the subscription retention (400 days after purge) consistent with the baseline's existing payment-record horizons? [Consistency, Spec §FR-047]

## Acceptance Criteria Quality

- [x] CHK017 - Can "no other person's data in the archive" be objectively verified (what is searched, against which values)? [Measurability, Spec §SC-002]
- [x] CHK018 - Is "purged within 2 hours" measurable given an hourly run plus retries? [Measurability, Spec §SC-004]
- [x] CHK019 - Are the "exactly one" audit entries (`export_requested`, `export_delivered`, `deletion_completed`, `role_changed` per transfer) each tied to a scenario? [Acceptance Criteria, Spec §FR-021, FR-055]

## Scenario Coverage

- [x] CHK020 - Are recovery requirements defined for a purge failing at each step, including between a step's commit and its progress marker? [Coverage, Recovery, Spec §FR-053]
- [x] CHK021 - Are requirements defined for concurrent purges of two members of the same wallet? [Coverage, Spec §Edge Cases]
- [x] CHK022 - Is the behaviour defined when a chosen successor has left, or is themselves deleting, at purge time? [Coverage, Spec §FR-031]
- [x] CHK023 - Are requirements defined for an export in flight when the account is purged, and for the download link after the purge? [Coverage, Spec §Edge Cases]
- [x] CHK024 - Is cancellation behaviour defined for each state (`pending_deletion`, `purging`, `purged`)? [Coverage, Spec §FR-052]

## Edge Case Coverage

- [x] CHK025 - Are transfers involving the departing person's solo wallet as the counter wallet of another wallet's transfer addressed? [Edge Case, Spec §Edge Cases]
- [x] CHK026 - Is the case of the departing person's categories referenced only by tombstoned records of a deleted solo wallet addressed? [Edge Case, Spec §FR-046]
- [x] CHK027 - Are CSV formula-injection and text-encoding edge cases (Arabic, BOM, line breaks inside memos) specified? [Edge Case, Spec §FR-005]

## Non-Functional Requirements

- [x] CHK028 - Is the at-rest protection of the export archive stated explicitly (and recorded in the baseline), rather than implied? [Security, Spec §FR-020]
- [x] CHK029 - Are logging exclusions specified for the export job, the download route and every purge step? [Security, Spec §FR-011, FR-056]
- [x] CHK030 - Are rate limits specified for every new endpoint and the download route, with the refusal shape? [Security, Spec §FR-018]
- [x] CHK031 - Is the key-destruction ordering (after invitations revoked and completion mail sent) specified? [Security, Spec §FR-043, FR-059]

## Dependencies & Assumptions

- [x] CHK032 - Is the absence of any new dependency (zip extension availability, no XLSX) recorded with the fallback? [Assumption, Spec §FR-072, Open question 1]
- [x] CHK033 - Is the dependency on the KMP 002 client sending `{password}` only treated as backward compatible in the contract? [Dependency, Spec §5]

## Contract

- [x] CHK034 - Does every new or changed operation have documented statuses for every refusal named in the spec? [Completeness, Spec §5]
- [x] CHK035 - Is the `current` member of `EXPORT_IN_PROGRESS` documented in the shared Problem schema and catalogue? [Completeness, Spec §FR-017, FR-070]

## Notes

Reviewed by the agent under the owner's delegation (2026-10-06). Gaps found and fixed in the spec before marking:

- CHK001: FR-002 now names what an account export deliberately excludes (activity, audit, claims, redeem codes).
- CHK002: FR-050 now lists every kept table (category references, operator notifications, payment claims added).
- CHK003: FR-056 requires encrypted queued mail jobs; the completion mail is never queued (FR-059); framework sessions in FR-049.
- CHK005: sign-in failure row deleted by lookup value before the value is cleared (FR-049; data-model and research R-12 aligned — they previously said "untouched").
- CHK008: "earliest membership" defined as the current membership record's creation; re-join restarts (FR-031).
- CHK010: CSV `wallet`/`category`/`counter_wallet` semantics defined (FR-005).
- CHK013: the B14 "users row last" note reconciled with the husk in the Reconcile table.
- CHK018: SC-004 qualified for failing steps.
- CHK020: FR-053 covers a crash between a step's commit and its progress record.
- CHK025: counter-wallet edge case rewritten to rely on the verified specs 010/012 delete behaviour instead of an unverified ADR-0015 rule.
- CHK026: FR-046 covers categories referenced only by tombstones of a deleted solo wallet.
- CHK015: §7 updated during planning to name `AccountPurgeStalled`.


## Implementation evidence (T057)

Each requirement above is now backed by a test or a file in the build. `[x]` items stay as the reviewer left them; this maps them to evidence.

| Items | Evidence |
|---|---|
| CHK001, CHK007, CHK017 | `tests/Feature/Identity/Export/ExportContentTest.php` (byte search for the other members' ids, names, addresses and unused categories; tombstone shape; no internal columns) |
| CHK002, CHK005 | `tests/Feature/Identity/Deletion/PurgeRemovesTest.php` (every table's fate; sign-in failure row, links, framework sessions, key) |
| CHK003, CHK029 | `tests/Feature/Identity/Deletion/PurgeAuditTest.php`, `GenerateExportTest.php` (log capture), `PurgeMailTest.php` (completion mail never queued; jobs that carry an address are `ShouldBeEncrypted`) |
| CHK004, CHK031 | `PurgeMailTest.php`, `DeletionReminderTest.php`, `GenerateExportTest.php` (language, content, link lifetime; mail sent while key and address exist) |
| CHK006 | `docs/security-baseline.md`, `docs/privacy-commitments.md`, `docs/glossary.md`, ADR-0024, constitution v1.3.13 |
| CHK008, CHK022 | `tests/Unit/Identity/Deletion/SuccessorRuleTest.php`, `PurgeOwnedWalletsTest.php`, `DeletionPlanTest.php` |
| CHK009, CHK012, CHK013 | `PurgeKeepsHistoryTest.php`, `PurgeRemovesTest.php`, `tests/Feature/Admin/AccountDeletionStateTest.php` |
| CHK010, CHK027 | `tests/Unit/Identity/Export/TransactionsCsvTest.php`, `ExportContentTest.php` |
| CHK011 | `DeletionPlanTest.php` (archived shared wallet included, deleted excluded) |
| CHK014, CHK028, CHK030 | `DownloadExportTest.php`, `ExpireExportsTest.php`, `RequestExportTest.php`; baseline rows |
| CHK016 | `tests/Feature/Billing/Departure/EndSubscriptionsTest.php` (400 days) |
| CHK019 | `RequestExportTest.php`, `DownloadExportTest.php`, `PurgeAuditTest.php`, `PurgeOwnedWalletsTest.php` |
| CHK020, CHK021 | `tests/Feature/Identity/Deletion/PurgeResumeTest.php` (every step, concurrent run, chained members) |
| CHK023 | `GenerateExportTest.php`, `DownloadExportTest.php` (account purging or purged: no file, no download) |
| CHK024 | `tests/Feature/Identity/Deletion/PurgingStateTest.php` |
| CHK025, CHK026 | `ReleaseCategoriesTest.php`, `PurgeKeepsHistoryTest.php` |
| CHK032 | `composer.json` and `package.json` unchanged; `ZipArchive` (ext-zip) in the container |
| CHK033, CHK034, CHK035 | `tests/Contract/Identity/ExportsTest.php`, `DeletionRequestTest.php`, `tests/Contract/OpenApi` |

Endpoint review checklist (`docs/api-conventions.md`) applied to the six operations: shape and errors (A, B) by the contract tests' `assertValidResponse` on every documented status; identifiers (C6, C7: another person's export is `404`, a malformed id `422 INVALID_ID`); no syncable resource (D), no write by id (C1 to C5 do not apply: exports are requested, not created by the client); collection grammar (E) on `listExports` with cursor, limit and the one sort. Security review checklist (`docs/security-baseline.md`) applied to the export request, the signed file route and each new stored attribute (`data_exports`, `deletion_successor_choices`, `account_purges`, the husk columns, the export archive): E1 to E6 and F1 to F5 by the log and audit tests above, D1 and D3 by the baseline's protected-attributes row for the archive (plain, private disk, signed link, 24 hours), G1 to G10 by the retention rows and the deletion and export tests. The signed route is deliberately outside `/api/v1` and out of OpenAPI (plan, Complexity Tracking).
