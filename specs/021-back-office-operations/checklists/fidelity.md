# Visual fidelity: Back-office operations (T067)

Design source: artifact `Ag9pG4RnifekirczEG2drM`, version `1791137074-53df`, boards `Overview`/`OverviewAr`, `Claims`/`ClaimsAr`, `UserLookup`/`UserLookupAr`, `Plans`/`PlansAr`, `Audit`/`AuditAr`. Composition follows the boards where the spec allows; where the spec's privacy and scope rules differ, the spec wins (research R-15). Comparison here is by construction (primitives, tokens, layout), not by a live browser pass (see T066 in tasks.md).

| Screen | Board | Built | Result |
|---|---|---|---|
| Overview | stat tiles, revenue, MAU, churn, sign-ups chart, revenue by source, engagement, sync, Play webhooks | counts-only tiles (`figure-<key>`) with the anonymity floor, a *Needs attention* card, the low-codes alert first | gap by design: no money, MAU, churn, charts or webhook figures (FR-013, P-18 rule 8) |
| Claim queue | pill tabs, email on each row | underline `x-admin::tabs` with counts, search in its own row above the table, reference on each row | gap: no address in lists (spec 020 FR-045); tab style from the shared primitive |
| Claim review | payer email, "Automatic checks" including a Play-subscription check | cards Payment, Payer, Receipt, Same reference, Review; checks reduced to amount vs. current price, duplicates, account exists; action buttons in a page header row | gap: no Play check (channel rule); header actions because the page layout does not forward the shell slot |
| Find an account | no board | search form composed from the input and button primitives, one miss message for every miss | composed without a board |
| Account page | avatar initial, language, timezone, last seen, per-wallet rows, device names, "Sign out all", support banner, "Admin actions" tab | description-list cards, four counts, subscriptions in the table primitive with per-row actions, the privacy note, the typed address only for the one request that found it | gaps by design (FR-021 to FR-025): counts not rows, no names, no sign-out, a link to the audit log instead of the tab |
| Grant, extend, revoke dialogs | no board | dialog primitive with reason field and hint "Don't include personal details", then the factor prompt | composed without a board |
| Plan catalogue | plan families, "New plan", "Lifetime" plan, inline feature matrix | one description-list card per plan, active and retired; Plus monthly and yearly are separate plans; Edit button per card | gaps: no plan creation (A-9), no families, no inline matrix editing |
| Plan editor | none | Details, InstaPay price and Capabilities cards above a sticky unsaved bar; a review dialog with before and after, then the factor prompt | composed without a board; price entered as plain minor units (the Alpine money input writes a hidden field Livewire cannot observe) |
| Audit log | email targets, price diff values, "Export range…" | filter card above the table, 50 per page with Older and Newer, expand toggle per row, footer "Newest first · from → to" | gaps by design: no emails, no values, no export (FR-046) |
| Notification bell | spec 007 slide-over | per-item "Open account", "Mark all read", unread items bold | extension, no board |
| Sidebar | Subscriptions and Operators entries | Claims, Accounts, Plans, Audit log with the registry's glyphs | gaps: Subscriptions and Operators not built (A-1) |
| Arabic | `*Ar` boards | `dir="rtl"`, logical utilities only (arch gate FR-031), Western digits, identifiers in left-to-right isolates (`BackOfficeLanguageTest`) | match |
| Tokens | board tokens | spec 006 token set, no arbitrary colours (arch gate FR-001) | match |

Screens composed without any board: Find an account, grant/extend/revoke dialogs, claim review dialogs, the plan editor and its review dialog, the notification bell actions.
