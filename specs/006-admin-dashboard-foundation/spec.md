# Feature Specification: Admin Dashboard Foundation

**Feature Branch**: `006-admin-dashboard-foundation`

**Created**: 2026-09-25

**Status**: Draft

**Input**: Brief D1 ([specs/briefs/D01-admin-dashboard-foundation.md](../briefs/D01-admin-dashboard-foundation.md)), plus the owner's note at specify time: "take the design system artifact to build livewire components and layout, no data or content yet, also support arabic rtl for the components and layouts".

> **Nature of this feature.** This spec delivers the **parts a back-office screen is built from, not the screens**. It covers the design tokens, the primitives, the dashboard shell, and a local gallery that proves them. It does not read application data and it ships no feature screens. Claim review (B11), plan catalog admin (B3) and back-office operations (B12) come later and are built from these parts. The stack is fixed by constitution P-18 (Livewire and Blade), so naming it here is subject matter, not leaked implementation detail. Where a tool would help (a ready-made kit, a one-time-code library), it is left as a decision the owner takes at planning.

## Design source

The brief says nothing is to be styled from guesswork, so the design this spec builds to is recorded here with its version.

| Item | Value |
|---|---|
| Artifact | **Save Me Admin — Calm Clarity System**, https://claude.ai/artifact/Ag9pG4RnifekirczEG2drM |
| Version | `1790356228-859b` (2026-09-25) |
| Lineage | Concept A "Calm Clarity" from the Save Me mobile concept exploration |
| Boards in force | 01 Foundations · 02 Actions · 03 Forms · 04 Data display · 05 Feedback & overlays · 06 Navigation & values. The RTL rules note, and the six Arabic RTL screen boards as the reference for right-to-left behaviour |
| Boards for reference only | The English and Arabic screen boards (sign-in, overview, claims, user lookup, plans, audit) and every dark variant. They show composition. Their content belongs to later specs, and dark is not shipped (FR-005) |

The component boards are drawn left-to-right only. Their right-to-left behaviour comes from the RTL rules in this spec (FR-030 to FR-037), checked against the Arabic screen boards. A later version of the design source replaces this one only by editing this table.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Build a back-office screen from ready primitives (Priority: P1)

A developer building a later back-office feature (such as the InstaPay claim review) composes the screen entirely from the foundation's primitives: buttons, fields, tables, pills, dialogs and value displays. They write no styling of their own, and the result matches the design source in both languages.

**Why this priority**: this is the whole reason the foundation exists. Without it, every later screen restyles its own controls and the back office drifts from the design.

**Independent Test**: build a throwaway page in a test from primitives only. Check that it renders every primitive's documented props and states, and that no styling is written outside the primitives.

**Acceptance Scenarios**:

1. **Given** the primitives catalogue, **When** a developer needs a primary, secondary, danger or ghost button in any of its sizes and states, **Then** a documented primitive provides it without extra styling.
2. **Given** a table of records, **When** the developer supplies columns, rows, sort state, filters, selection and row actions, **Then** the data table renders them, including its empty and loading states and cursor pagination.
3. **Given** an amount held as integer minor units and a currency, **When** it is shown through the money primitive, **Then** it reads correctly with Western numerals, and no floating-point value exists anywhere between input and output.
4. **Given** a state-changing action marked as audited, **When** the operator triggers it, **Then** the reason prompt opens first, and the action cannot run without a reason.

---

### User Story 2 - Work in Arabic, right to left (Priority: P1)

An operator switches the back office to Arabic. The shell and every primitive mirror correctly: the sidebar moves to the right, direction-bearing icons flip, and reading order runs right to left. Emails, identifiers, references, dates and numbers still read left to right, and every piece of text the primitives own appears in Arabic.

**Why this priority**: Arabic is the product's primary market language (P-14), and the owner asked for right-to-left support in this feature. Retrofitting direction onto finished components costs more than building it in.

**Independent Test**: open the gallery in Arabic and compare each primitive against the RTL rules and the Arabic screen boards. Separately, check that every text key a primitive uses exists in both Arabic and English.

**Acceptance Scenarios**:

1. **Given** the language is set to Arabic, **When** any page renders, **Then** the page direction is right-to-left and the sidebar, breadcrumbs, pagination arrows and detail panels are mirrored.
2. **Given** Arabic is active, **When** an email, identifier, InstaPay reference, calendar date or one-time-code field is shown, **Then** it keeps left-to-right order and does not scramble surrounding Arabic text.
3. **Given** Arabic is active, **When** an amount is shown, **Then** it uses Western numerals and the Arabic currency form of the design (for example `1,290 ج.م`).
4. **Given** either language, **When** a primitive shows its own text ("Cancel", "Older", "Copied", "Required"), **Then** the text comes from the translation files in that language.

---

### User Story 3 - A shell every screen plugs into (Priority: P2)

A developer adds a new back-office section by registering a navigation entry (group, label, icon, destination, optional count badge). The sidebar shows it with the correct active state, and the page gets breadcrumbs, a page header with actions, and the content area. The developer does not edit the shell.

**Why this priority**: the shell is shared by every later screen, but screens can be drafted inside it once primitives exist. It follows the primitives.

**Independent Test**: register a fixture entry in a test and render a page under it. Check that the entry appears in its group with its badge and active state, and that no shell file changed.

**Acceptance Scenarios**:

1. **Given** two features each register navigation entries, **When** the shell renders, **Then** both appear under their groups in a stable order, and neither feature touched shell files.
2. **Given** the current page belongs to an entry, **When** the shell renders, **Then** that entry is marked current for both sighted and screen-reader users.
3. **Given** the environment is production, **When** any shell page renders, **Then** an environment badge in the top bar marks it as production, distinct from local and staging.

---

### User Story 4 - Review the design without building a feature (Priority: P2)

A reviewer opens the primitives gallery in the local environment. It shows every primitive in every state, with a switch for language and direction. The reviewer checks the build against the design source without any feature existing.

**Why this priority**: it is the brief's review mechanism and the proof for the success criteria, but it adds no capability of its own.

**Independent Test**: request the gallery in the local environment and in any other environment. The first shows every catalogue entry. The second behaves as if the page does not exist.

**Acceptance Scenarios**:

1. **Given** the local environment, **When** the reviewer opens the gallery, **Then** every primitive in the catalogue appears in every state the catalogue lists, using sample text only.
2. **Given** staging or production, **When** anyone requests the gallery address, **Then** the response is not-found.
3. **Given** the gallery, **When** the reviewer switches to Arabic, **Then** every primitive re-renders right-to-left in place.

---

### Edge Cases

- A long Arabic label in the sidebar, a tab or a button truncates with an ellipsis and exposes the full text to assistive technology. It never pushes an icon or badge out of place.
- A left-to-right value inside right-to-left text (such as `CLM-2046 · omar@example.com`) keeps its own order and does not reorder the Arabic around it.
- An amount of zero, a negative amount (an amount owed, shown as a positive "owed" figure per glossary convention), and an amount above one billion minor units all render correctly. A currency the money primitive doesn't know is shown by its ISO code rather than failing.
- A compact figure ("1.28M") appears only in aggregate stat tiles, and the full value is available on hover or focus.
- An instant exactly at a daylight-saving change shows the correct offset abbreviation.
- A data table with zero rows shows its empty state. A table whose data is still loading shows its loading state, never a flash of the empty state first.
- A stat tile whose group is below the anonymity floor shows the suppressed state ("Fewer than 10") instead of a number.
- Opening a dialog moves focus into it and traps it there. Closing it by Escape, the close button or the backdrop returns focus to the control that opened it.
- A reason prompt submitted empty or with whitespace only is refused with a field error, and the action does not run.
- A disabled menu item stays visible and shows why it is disabled.
- A navigation entry registered twice with the same key is a startup error, not a silent duplicate.

## Requirements *(mandatory)*

### Functional Requirements

**Design tokens**

- **FR-001**: The back office MUST take every colour, type size and weight, spacing step, corner radius, shadow and focus ring from one token set matching the design source's Foundations board. Primitives MUST reference tokens and MUST NOT carry literal colour or size values.
- **FR-002**: The token set MUST include the design's text-contrast adjustments: darker secondary grey, darker positive/over/pending text colours, and a darker field border. Every text/background pair used by a primitive MUST then meet WCAG 2.1 AA (4.5:1 for body text, 3:1 for large text and for non-text boundaries of controls).
- **FR-003**: Status colour MUST follow the design's meaning map: sage for positive, amber for pending, clay for over/destructive/error, blue for selected/info/primary, grey for neutral. Blue MUST be the only decorative hue.
- **FR-004**: Icons MUST come from one set drawn on a 24-unit grid with rounded strokes, matching the design source. A control that shows only an icon MUST have an accessible name.
- **FR-005**: The back office MUST ship the light theme only (owner decision, 2026-09-25). The token set MUST be organised so a dark theme can be added later by supplying alternative token values, with no change to any primitive. The design's dark boards are reference only.

**Shell**

- **FR-006**: The system MUST provide an authenticated layout with a sidebar (brand, grouped navigation, operator block with sign-out), a top bar (breadcrumbs, a global-search slot, an environment badge, a notifications slot), a page header (title, optional subtitle and status, action area) and a content area, as drawn on the Navigation board.
- **FR-007**: The system MUST provide a navigation registry. A feature adds an entry (a unique key, group, translated label, icon, destination, optional count-badge source) without editing the shell. Entries MUST keep a stable, declared order.
- **FR-008**: The shell MUST mark the current entry visually and to assistive technology, from the current page.
- **FR-009**: The environment badge MUST name the environment in the active language, and production MUST be visually distinct from local and staging.
- **FR-010**: The shell MUST render with no feature registered, showing an empty navigation group state and an empty content area rather than failing.

**Primitives**, each with documented props and every state listed:

- **FR-011 Actions**: button in primary, secondary, danger and ghost; small, medium and large; optional leading or trailing icon; default, hover, focus, pressed, loading and disabled. Link in default, quiet and external forms. Icon button. Segmented control. Split button. Dropdown menu with a section title, icons, shortcut hints, a separator, a disabled item that shows its reason, and a destructive item placed last.
- **FR-012 Forms**: text, number, money (currency code and amount; the minor-unit value it stores is shown as a hint), textarea with a character counter, select (closed and open), checkbox (unchecked, checked, indeterminate, disabled), radio group in the card style, toggle (on, off, disabled), calendar-date picker, instant picker (date plus time, showing the operator's timezone and the stored UTC value), search field with a keyboard shortcut hint. Also a field wrapper (label, required marker, hint, error), a form section (title, description, fields) and a validation summary whose entries link to the fields in error.
- **FR-013 Data**: data table with columns, sort indicator, filter chips (applied and add-filter), bulk selection with an indeterminate header, row actions, hover and selected rows, empty state, loading state and cursor pagination ("newer / older", no page numbers). Description list. Stat tile in hero-ring, default, positive delta, negative delta, with-sparkline, loading and suppressed forms. A delta's colour follows its meaning, not its direction. Card with an optional header, actions and footer.
- **FR-014 Feedback**: badge (plan, role, source, count, dot), status pill in the five tones, alert in info, success, warning and error with an optional action, toast (stacked, dismissible, with an optional undo, auto-dismissing except errors), empty state, skeleton, confirmation dialog (including a typed-confirmation variant for irreversible actions), modal and slide-over.
- **FR-015 Navigation**: tabs in the underline form (with optional count) and the pill form, breadcrumbs, and pagination in the cursor form (default) and the numbered form (for small fixed lists only).
- **FR-016 Value display**: money, calendar date, instant, relative time and identifier-with-copy, per FR-020 to FR-024.
- **FR-017 Reason prompt**: a dialog with a title naming the action, a one-line consequence, preset reason chips, a required free-text reason (at most 500 characters, with a counter), the audit attribution line (the operator and the action name) and confirm/cancel.
- **FR-018**: Every primitive that performs an audited action MUST open the reason prompt first. It MUST refuse to run the action without a non-blank reason and MUST hand the reason to the action.
- **FR-019**: Every interactive primitive MUST be operable by keyboard alone, with a visible focus ring. Overlays MUST trap focus, close on Escape and restore focus. Menus and listboxes MUST support arrow keys, Enter and Escape.

**Values**

- **FR-020**: The money primitive MUST accept only an integer amount in minor units and an ISO 4217 currency code. It MUST NOT use floating-point arithmetic, and MUST render Western numerals with the currency's minor-unit precision in both languages. English places the code before the amount (`EGP 1,290.00`); Arabic follows the design (`1,290 ج.م`).
- **FR-021**: A compact money form MUST be available for aggregate tiles only, with the full value exposed on hover and focus.
- **FR-022**: A calendar date MUST render without any timezone shift.
- **FR-023**: An instant MUST render in the operator's timezone with the zone's abbreviation always visible. Relative time MUST be used for instants under seven days old, with the absolute instant on hover and focus.
- **FR-024**: An identifier MUST render in a monospace face, truncated in the middle, with a copy control that copies the full value and confirms "Copied" for a moment.

**Arabic and right-to-left**

- **FR-030**: The shell and every primitive MUST support English (left-to-right) and Arabic (right-to-left). Direction MUST follow the active language, and no primitive may assume a direction.
- **FR-031**: In right-to-left, layout MUST mirror: sidebar on the right, start/end spacing swapped, detail panels on the opposite side, and chart time axes running right to left.
- **FR-032**: Icons that indicate direction (chevrons, back/forward, sign-out, trend arrows, progress rings) MUST mirror in right-to-left. Icons without direction (search, lock, check, calendar) MUST NOT mirror.
- **FR-033**: Emails, identifiers, references, codes, calendar dates, instants, URLs and one-time-code inputs MUST stay left-to-right inside right-to-left text and MUST NOT reorder the text around them.
- **FR-034**: Numerals MUST be Western (0–9) in both languages (P-14).
- **FR-035**: Arabic text MUST use the design's Arabic typeface, with numerals staying in the Latin face. Arabic text MUST NOT have letter-spacing or upper-casing applied.
- **FR-036**: Every piece of text a primitive or the shell shows (labels, button text, empty states, pagination, "Copied", "Required", environment names, the reason-prompt copy) MUST come from translation files present in both Arabic and English. None may be hard-coded.
- **FR-037**: An operator MUST be able to switch language from the operator block. The choice MUST persist across pages for the rest of the session. The first visit uses the browser's preferred language when it is Arabic or English, and English otherwise.

**Gallery**

- **FR-040**: A gallery page MUST show every primitive from FR-011 to FR-017 in every listed state, with a language/direction switch. It MUST use sample text only and MUST read no application data.
- **FR-041**: The gallery MUST exist only in the local environment. In every other environment its address MUST respond as not found.

**Scope limits**

- **FR-050**: This feature MUST NOT add any screen that reads or changes application data, and MUST NOT add any `/api/v1` endpoint.
- **FR-051**: Operator sign-in, its second factor, recovery codes, two-operator re-enrolment and the first-operator command are out of scope (owner decision, 2026-09-25). They move to a sibling spec, which must land before B11 or B12. Until then, the local gallery MUST be the only page registered under `/admin`. The shell MUST take the signed-in operator's name and the sign-out destination as inputs, so the sibling spec connects them without editing the shell. The gallery shows them with sample values.

### Key Entities

- **Navigation entry**: a registered item in the sidebar. It has a unique key, group, translated label, icon, destination, order and optional count-badge source. It is configuration, not stored data. There is no per-entry visibility rule, because every operator is an admin in MVP.
- **Design token set**: the named colours, type, spacing, radii, shadows and focus ring the primitives draw from. It is versioned with the design source.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature gives the Save Me back office its look, its parts and its frame. It turns the approved Calm Clarity design into a catalogue of reusable, bilingual, right-to-left-ready primitives and a dashboard shell. Every later back-office screen (claim review, plan catalog, user lookup, the audit log) is then composed rather than styled, stays true to the design in Arabic and English, and inherits the accessibility and value-display rules once instead of re-deciding them.

### 2. Glossary terms

- [Admin](../../docs/glossary.md#notifications-and-admin): the context that owns the back office, admin accounts and the audit of admin actions.
- **Operator**: a person who uses the back office through an admin account, as the [security baseline](../../docs/security-baseline.md) uses the word. **In MVP every operator is an admin.** There is one global role, platform admin (P-08 rule 4), and no finer operator roles, so no primitive or navigation entry varies by role. Operator is not an entity this spec creates, so it gets no glossary entry here. The sibling sign-in spec adds the **Operator Account** entry.
- **Back office**: the only server-rendered interface ([constitution P-18](../../.specify/memory/constitution.md)).
- **Money**, **minor units**, **calendar date**, **instant**: as fixed by ADR-0003, ADR-0011 and the glossary's field conventions.

### 3. Data model

None: the feature stores nothing. Navigation entries are code-level configuration, and the language choice lives in the session. Operator accounts belong to the sibling sign-in spec.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| Back-office Livewire components live only in `app/Modules/Admin/Http/Livewire` | P-18 rule 3 | `tests/Arch/AdminBackOfficeTest.php` |
| Back-office views are registered under the Admin module's view namespace; none sit in `resources/views` | P-18 rule 3 | `tests/Arch/AdminBackOfficeTest.php` |
| No Livewire public property is typed as an Eloquent model | P-18 rule 5 | `tests/Arch/AdminBackOfficeTest.php` |
| Every translation key a primitive or the shell uses exists in both `ar` and `en` | P-14 rule 1, FR-036 | `tests/Feature/Admin/TranslationCompletenessTest.php` |
| The money primitive accepts only an integer and a currency, and outputs Western numerals in both locales | P-03, P-14 rule 2, FR-020 | `tests/Unit/Admin/MoneyDisplayTest.php` |
| A calendar date never shifts with timezone; an instant always shows its zone | P-12, FR-022, FR-023 | `tests/Unit/Admin/TemporalDisplayTest.php` |
| An audited action cannot run without a non-blank reason | FR-018, security baseline (`admin_action`) | `tests/Feature/Admin/ReasonPromptTest.php` |
| The gallery responds not-found outside the local environment | FR-041 | `tests/Feature/Admin/GalleryAccessTest.php` |
| Pages render right-to-left when Arabic is active and left-to-right otherwise | FR-030 | `tests/Feature/Admin/DirectionTest.php` |
| A navigation key registered twice fails at startup | FR-007 | `tests/Feature/Admin/NavigationRegistryTest.php` |
| The local gallery is the only route registered under `/admin` until the sign-in spec adds the operator guard | P-18 rule 4, P-08 rule 4, FR-051 | `tests/Feature/Admin/AdminRouteSurfaceTest.php` |

### 5. API

Not applicable: the back office serves no `/api/v1` endpoints (P-18 rule 2), and this feature adds none (FR-050).

### 6. Authorization and entitlements

No wallet roles and no entitlements apply; the back office is outside wallet-scoped authorization (P-08 rule 4). Every operator is an admin in MVP, so there is no role matrix. The separate admin guard and its second factor come with the sibling sign-in spec. Until it lands, this feature exposes no `/admin` route except the local-only gallery, which reads no data and performs no action (FR-041, FR-051). P-18 rule 4 therefore holds by absence. Components authorize on every request (P-18 rule 7); that rule becomes testable with the first data screen.

### 7. Events

None published or consumed.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–2 primitives render documented variants and states | Feature | `tests/Feature/Admin/Primitives/*Test.php` (one per primitive family) |
| US1 · 3 money from minor units | Unit | `tests/Unit/Admin/MoneyDisplayTest.php` |
| US1 · 4 reason prompt gates audited actions | Feature | `tests/Feature/Admin/ReasonPromptTest.php` |
| US2 · 1 direction follows language | Feature | `tests/Feature/Admin/DirectionTest.php` |
| US2 · 2 left-to-right isolation of values | Feature | `tests/Feature/Admin/Primitives/ValueDisplayTest.php` |
| US2 · 3 Western numerals in Arabic | Unit | `tests/Unit/Admin/MoneyDisplayTest.php` |
| US2 · 4 translated primitive text | Feature | `tests/Feature/Admin/TranslationCompletenessTest.php` |
| US3 · 1–2 registry and active state | Feature | `tests/Feature/Admin/NavigationRegistryTest.php` |
| US3 · 3 environment badge | Feature | `tests/Feature/Admin/ShellTest.php` |
| US4 · 1, 3 gallery completeness and direction switch | Feature | `tests/Feature/Admin/GalleryTest.php` |
| US4 · 2 gallery hidden outside local | Feature | `tests/Feature/Admin/GalleryAccessTest.php` |
| Visual fidelity to the design source | Manual review | the checklist produced at planning, run against the gallery |

The Contract layer has nothing here, because the feature adds no API.

### 9. Open questions

Decided 2026-09-25 by the owner: operator sign-in is split into a sibling spec (FR-051), the back office ships the light theme only (FR-005), and every operator is an admin in MVP.

1. **Sibling spec for operator sign-in.** The sign-in, second factor, recovery codes, two-operator re-enrolment and first-operator command need their own brief, ordered before B11 and B12. The one-time-code implementation choice (a small package, which needs approval, or in-house RFC 6238) moves there. This doesn't block D1, but it does block any data screen.
2. **Ready-made kit or in-house primitives.** A kit such as Flux is a new dependency that needs the owner's approval. The owner decides at planning; it doesn't block the spec.
3. **Right-to-left component boards.** The design source draws the component boards left-to-right only. This spec's RTL rules plus the Arabic screen boards govern. If the owner wants RTL component boards drawn before build, the design source is updated and the table above re-versioned. This doesn't block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Every primitive and every state listed in FR-011 to FR-017 appears in the gallery. A reviewer's count against the catalogue finds 0 missing.
- **SC-002**: A side-by-side review of the gallery against the design source finds 0 colours off the token set and 0 spacing or radius values off the scale.
- **SC-003**: Reviewed in Arabic, every primitive and the shell show 0 mirroring defects and 0 scrambled left-to-right values, judged against FR-031 to FR-033 and the Arabic screen boards.
- **SC-004**: A keyboard-only reviewer can reach, operate and leave 100% of the interactive primitives in the gallery, with focus visible throughout.
- **SC-005**: 100% of text/background pairs used by primitives meet WCAG 2.1 AA contrast in the light theme.
- **SC-006**: A developer adds a navigation section and a page to the shell with 0 changes to shell files, demonstrated by a fixture in the test suite.
- **SC-007**: 0 user-visible strings in primitives or the shell lack a translation in either Arabic or English.
- **SC-008**: The gallery address returns not-found in 100% of requests outside the local environment.
- **SC-009**: The first later screen (B11 claim review or B12 back office) is built with no styling written outside the primitives, confirmed in its review.

## Assumptions

- Operators are internal staff who are comfortable in both languages. The back office offers both on equal footing, with English as the fallback for a first visit.
- Every operator is an admin in MVP (owner, 2026-09-25). Finer operator roles would need a later decision, and would add a visibility rule to navigation entries.
- The language choice is kept for the session only. Storing it per operator waits for operator accounts in the sibling sign-in spec.
- Desktop and laptop are the supported viewports, 1280 px wide and up. Tablet and phone layouts for the back office are out of scope.
- Livewire 4, Blade, Tailwind 4 and `livewire/blaze` are already installed, so building on them needs no approval. Any further package does.
- The Arabic typeface in the design (IBM Plex Sans Arabic) and the Latin face (Hanken Grotesk) are free web fonts. Serving them self-hosted or from a font service is decided at planning, under the security baseline's content-security rules.
- Sample text in the gallery is invented and marked as such. It is not seeded into any database.
- The design source is complete enough to build from. Where a state is missing (for example a hover state not drawn), the nearest drawn state and the token set govern, and the gap is noted in the planning review.
