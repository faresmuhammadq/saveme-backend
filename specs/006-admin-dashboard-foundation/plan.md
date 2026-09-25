# Implementation Plan: Admin Dashboard Foundation

**Branch**: `006-admin-dashboard-foundation` (trunk-based; work lands on `main`) | **Date**: 2026-09-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/006-admin-dashboard-foundation/spec.md`

## Summary

Turn the Calm Clarity design source (artifact version `1790356228-859b`) into the back office's building blocks, inside the Admin module:

- **Tokens**: one Tailwind 4 token file.
- **Primitives**: about forty stateless anonymous Blade components (`<x-admin::…>`), with Alpine for in-component behaviour.
- **Shell**: a layout with a navigation registry that features plug into.
- **Value presenters**: money is formatted with integer maths only; instants and dates through Carbon.
- **Reason prompt**: made structural through an `AuditReason` value object and a `RequiresReason` Livewire trait.
- **Language**: a session-scoped language switch.
- **Gallery**: a local-only gallery that shows everything in English and Arabic.

Right-to-left is built in from the start, not added afterwards. Primitives use only logical utilities, an icon registry records which icons mirror, and values that must stay left-to-right are isolated. Arch tests enforce the logical-utility and token rules. There are no new dependencies, no data, and no route beyond the local gallery. Operator sign-in is a sibling spec, and the shell accepts its operator and sign-out inputs.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13, Livewire 4.4.5 (with bundled Alpine: `x-trap`, `x-anchor`, `x-collapse`, `x-mask`), Tailwind CSS 4, Vite 8 through `vite-plus`, laravel-vite-plugin 3.2.0.

**Primary Dependencies**: none new (R-1). `livewire/blaze` 1.0.18 is already installed and is used compile-only (R-15). Carbon (framework) handles temporal display (R-9). `intl` is present but deliberately unused for money (R-8).

**Storage**: none. The language choice lives in the session (R-7); navigation entries are in-memory configuration (R-12).

**Testing**: Pest 5 across the existing suites. Unit covers the presenters and `AuditReason`. Feature covers primitive rendering, the shell, the registry, the gallery, locale and direction, and the reason prompt. Arch covers the P-18 placement rules and the RTL and token lint. Visual fidelity is a manual checklist (R-16).

**Target Platform**: evergreen desktop browsers at 1280 px and up. The server runs in the `dev` container locally and in the Laravel deployment later.

**Project Type**: server-rendered admin UI foundation (Livewire and Blade) inside a modular-monolith web service.

**Performance Goals**: the gallery (every primitive in every state) renders server-side in under 1 s locally. A shell page in the later features adds under 50 ms of primitive rendering. Blaze compile-only (R-15).

**Constraints**:
- P-18 rules 2, 3 and 5 on placement and state.
- Nothing outside the four module layers.
- No physical-direction or arbitrary-value utilities in Admin views.
- Western numerals only, and no float anywhere in money display.
- Light theme only, with tokens ready for dark.
- No `/admin` route other than the local gallery.

**Scale/Scope**:
- about 40 primitives in 6 families;
- 1 layout;
- 3 Livewire classes (`Gallery`, `LocaleSwitcher`, and the fixture for tests) plus 1 trait;
- 3 presenters, 1 value object, 1 registry, 1 middleware, 1 provider;
- 8 translation files in each of 2 languages;
- about 30 icons.

## Constitution Check

*Constitution v1.3.0 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Uses the constitution's own words (Admin context, back office, operator). The copy for money, calendar date and instant follows ADR-0003 and ADR-0011 and the glossary field conventions; no new entity names |
| P-02 Module boundaries | PASS | Everything lives under `app/Modules/Admin/{Domain,Http}`: `AuditReason` in Domain; the provider, Livewire, views, lang, assets, presenters and navigation in Http. There are no cross-module imports. `tests/Arch/ModuleBoundariesTest.php` already polices this |
| P-03 Money | PASS | `MoneyPresenter` takes `int` minor units plus an ISO 4217 code and uses integer and string arithmetic only (R-8), proven by `tests/Unit/Admin/MoneyDisplayTest.php` |
| P-04 Ledger integrity | N-A | No ledger code |
| P-05 Derived balances | N-A | No balance is read or shown |
| P-06 Identifiers | PASS | The identifier primitive shows UUIDv7 values middle-truncated and copies them in full; it creates none |
| P-07 Sync and concurrency | N-A | No syncable entity |
| P-08 Authorization | PASS | Rule 4: no operator route exists yet besides the local gallery (R-13), which is asserted by `AdminRouteSurfaceTest`. The guard arrives with the sibling sign-in spec |
| P-09 Entitlements | N-A | The back office has no entitlement gates |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | N-A | No events published or consumed |
| P-12 Time | PASS | Instants display from UTC in the operator's timezone with the zone always shown; a calendar date is never shifted (R-9, `TemporalDisplayTest`) |
| P-13 Naming | PASS | The product name in the shell comes from `config('app.name')` and a translation, not from a namespace, class or path. Module and namespace names are `Admin` |
| P-14 Localisation | PASS | Every string sits in `admin::` translation files in `ar` and `en` (`TranslationCompletenessTest`). Western numerals are guarded by the U+0660–U+0669 assertion (R-9). Direction comes from the locale (R-5) |
| P-15 Observability | N-A | Nothing is logged. The gallery and switcher write no audit or log entries |
| P-16 Quality gates | PASS | New Unit, Feature and Arch tests, and no new dependency (R-1, R-6 reuses the installed plugin). Pint, Larastan level 8 and `composer test` must pass |
| P-17 Scope guard | PASS | Dark mode, which is on the MVP guard list, is **not** shipped. Tokens are only structured for it (FR-005, owner decision). No other guarded capability is touched |
| P-18 Delivery surfaces | PASS | Rule 2: Livewire and Blade only. Rule 3: Livewire classes under `app/Modules/Admin/Http/Livewire` and views registered from the module (`AdminBackOfficeTest`). Rule 4: no reachable `/admin` route outside local. Rule 5: primitives are stateless, and Livewire public properties hold scalars only (Arch test). Rule 6: nothing writes. Rule 7: `RequiresReason` re-authorizes on confirm. Rule 8: the feature reads no user data |

**Post-design re-check**: unchanged, all PASS or N-A. Phase 1 design added no dependency, no stored data and no route. The component contracts keep every primitive stateless (P-18 rule 5), and the reason-prompt contract makes an audited action without a reason unrepresentable in code. Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/006-admin-dashboard-foundation/
├── spec.md
├── plan.md                  # this file
├── research.md              # R-1 … R-18
├── data-model.md            # in-memory types only; nothing persisted
├── quickstart.md            # run and review guide, including the RTL and fidelity checklist
├── contracts/
│   ├── components.md        # every primitive: props, slots, states, ARIA, RTL notes
│   ├── shell-and-navigation.md
│   ├── reason-prompt.md
│   ├── values-and-rtl.md    # money, temporal, identifier output rules and the RTL rules
│   └── tokens.md            # the token set, mapped from the design source
├── checklists/requirements.md
└── tasks.md                 # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Admin/
├── Domain/
│   ├── AuditReason.php
│   └── Exceptions/InvalidAuditReason.php
└── Http/
    ├── AdminServiceProvider.php          # views, components, lang, Livewire namespace, routes, Blaze, registry
    ├── routes.php                        # /admin/_gallery, local only
    ├── Middleware/ResolveAdminLocale.php
    ├── Navigation/
    │   ├── NavigationRegistry.php
    │   ├── NavigationGroup.php
    │   ├── NavigationEntry.php
    │   └── DuplicateNavigationEntry.php
    ├── Shell/OperatorPresence.php         # operator block input (sign-in spec binds it)
    ├── Presenters/
    │   ├── MoneyPresenter.php
    │   ├── TemporalPresenter.php         # calendar date, instant, relative time
    │   └── IdentifierPresenter.php
    ├── Icons/IconRegistry.php            # name → SVG paths, mirrors flag
    ├── Livewire/
    │   ├── Gallery.php
    │   ├── LocaleSwitcher.php
    │   └── Concerns/RequiresReason.php
    ├── Views/
    │   ├── components/                   # <x-admin::…> anonymous primitives
    │   │   ├── layouts/shell.blade.php
    │   │   ├── shell/{sidebar,topbar,page-header,nav-item}.blade.php
    │   │   ├── button, link, icon-button, segmented, split-button, menu/{menu,item,separator}
    │   │   ├── field, input, number, money-input, textarea, select, checkbox, radio-group, toggle,
    │   │   │   date-picker, instant-picker, search, form-section, validation-summary
    │   │   ├── table/{table,column,row,cell,filters,pagination}, description-list, stat-tile, card
    │   │   ├── badge, pill, alert, toast-stack, empty-state, skeleton, dialog, confirm-dialog,
    │   │   │   modal, slide-over, reason-prompt
    │   │   ├── tabs, breadcrumbs, pagination
    │   │   └── money, date, instant, relative-time, identifier, icon, ltr
    │   └── livewire/{gallery,locale-switcher}.blade.php
    ├── Lang/{en,ar}/{shell,actions,forms,data,feedback,values,reason,gallery}.php
    └── Assets/{admin.css,admin.js}       # tokens (@theme), :lang(ar) rules, Alpine helpers

bootstrap/providers.php                   # + AdminServiceProvider
config/admin.php                          # timezone (Africa/Cairo), gallery samples switch
vite.config.js                            # + admin.css, admin.js inputs; + fonts (Hanken Grotesk, IBM Plex Sans Arabic)

tests/Unit/Admin/{MoneyDisplayTest,TemporalDisplayTest,AuditReasonTest}.php
tests/Feature/Admin/
├── Primitives/{Actions,Forms,Data,Feedback,Navigation,ValueDisplay}Test.php
├── {Shell,NavigationRegistry,Direction,Gallery,GalleryAccess,AdminRouteSurface,
│    TranslationCompleteness,ReasonPrompt,LocaleSwitcher}Test.php
└── Fixtures/AuditedFixture.php           # Livewire component exercising RequiresReason
tests/Arch/AdminBackOfficeTest.php        # P-18 r3/r5 placement, RTL lint, token lint
```

**Structure Decision**: a single module, Admin, using only the Domain and Http layers. There is no Application or Infrastructure code because nothing is queried or stored. `config/admin.php` is the only file outside the module besides wiring (`bootstrap/providers.php`, `vite.config.js`). The test fixture Livewire component lives under `tests/`, so the production namespace holds only production classes. The Arch placement rule scans `App\` only.

## Complexity Tracking

No Constitution Check violations; nothing to justify.
