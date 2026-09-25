# D1 · Admin dashboard foundation

**Call with**: `/speckit-specify D1` · **Source**: owner's request, recorded 2026-09-25 · **Replaces**: the Filament panel in the original B12 brief

## Description

The foundation every back-office screen is built on: a Livewire and Blade component library implementing the provided design, the dashboard shell, and the operator sign-in that guards it. Later specs — plan catalog admin (B3), claim review (B11), back-office operations (B12) — add screens by composing these primitives rather than styling their own.

**Shell**: authenticated layout under `/admin` with sidebar navigation, top bar, breadcrumbs, page header with actions, and a navigation registry a feature adds its entries to without editing the shell.

**Primitives**, each a Blade component with documented props and states:

- actions: button (primary, secondary, danger, ghost; loading and disabled states), link, icon button, dropdown menu
- forms: text, number, textarea, select, checkbox, toggle, calendar-date and instant pickers, field wrapper with label, hint and error, form section, and a validation summary
- data: data table (columns, sort, filters, row actions, empty and loading states, cursor pagination), description list, stat tile, card
- feedback: badge and status pill, alert, toast, empty state, skeleton, confirmation dialog, modal, slide-over
- navigation: tabs, breadcrumbs, pagination
- value display: **money** (from integer minor units and a currency, Western numerals, no floating-point arithmetic), calendar date, instant shown in the operator's timezone, relative time, identifier with copy
- a **reason prompt**: the dialog every audited action uses to capture why it was taken

**Operator access**: sign-in for operator accounts, separate from application users, with the second factor, its recovery codes and the two-operator re-enrolment the security baseline fixes; an artisan command to create the first operator.

**A primitives gallery** at `/admin/_gallery`, local environment only, showing every component in every state, so a reviewer checks the design without building a feature.

## Considerations

- **Design source**: *not ready yet* — the owner will provide it later (2026-09-25). Do not start `/speckit-specify D1` until it exists. The spec must name it — Figma file, exported tokens, screenshots — and record its version. Nothing is styled from guesswork.
- Stack is fixed by P-18: Livewire 4, Blade, Tailwind 4 (installed), no second interface stack. `livewire/blaze` is already installed for compiled Blade components.
- A ready-made kit (Flux or similar) would shorten the work but is a dependency needing the owner's approval; the alternative is building the primitives in-house to the design. Decide at plan time.
- Placement per P-18 rule 3: components and views in `app/Modules/Admin/Http`, registered under an `admin::` view namespace, never in the shared `resources/views` tree.
- Components hold scalars and DTOs only (P-18 rule 5); a form binds to a DTO and submits through an application service (rule 6).
- The data table reads through query services, not Eloquent, so it cannot become a way around the module boundaries or around rule 8 — no screen shows an individual user's amounts, balances or notes.
- Every state-changing primitive that performs an audited action goes through the reason prompt and writes an `admin_action` entry.
- Operators are internal staff: decide whether the back office ships in Arabic as well as English (P-14 covers user-facing text), and whether it needs right-to-left layout.
- The second factor needs a time-based one-time-code implementation: a small package (approval) or an in-house RFC 6238 implementation.
- Extend the architecture gates: Livewire components only under `Admin/Http/Livewire`, and no Livewire public property typed as an Eloquent model.

## Reconcile at specify time

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Build to "the provided design" | No design is in the repository | Owner supplies the design before `/speckit-specify D1` |
| Operator accounts and 2FA | Security baseline: time-based codes, ten recovery codes, two-operator re-enrolment; ADR-0006: separate guard and accounts owned by Admin | Implement as written there |
| Dark mode | MVP Scope Guard lists dark mode as "a client concern", out of scope | Applies to the app; confirm whether it binds the back office |
| Audit log reads | Reading the log is itself an `audit_read` event | The audit viewer lands in B12 on these primitives |
| Operator view of user data | P-18 rule 8; the paused operator-access decision (user-granted support windows) | This foundation must not create a way around it |
