---

description: "Task list for the admin dashboard foundation (D1)"
---

# Tasks: Admin Dashboard Foundation

**Input**: Design documents from `/specs/006-admin-dashboard-foundation/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: included. The spec's Invariants table and Acceptance index (Project Sections 4 and 8) name every test file, and the project's rules require regression tests. Write each story's tests first and watch them fail.

**Organization**: grouped by user story (spec.md): US1 primitives (P1), US2 Arabic/RTL (P1), US3 shell (P2), US4 gallery (P2).

**Conventions for every task**:
- Run commands through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev artisan …`.
- Use `dev artisan make:* --no-interaction` where a generator fits, then move the file into the module path.
- Primitives are anonymous Blade components under `app/Modules/Admin/Http/Views/components/`, used as `<x-admin::name>`. They are stateless and use **logical utilities only** (`ms-/me-/ps-/pe-/start-/end-/text-start/text-end/border-s/border-e/rounded-s/rounded-e`). They use no arbitrary values (`[#…]`, `[…px]`) and no inline colour styles, and all built-in text is `__('admin::…')`.
- Follow [contracts/components.md](./contracts/components.md) for props, slots, states, ARIA and RTL notes, and [contracts/tokens.md](./contracts/tokens.md) for values.
- Classes need explicit types, constructor promotion, PHPDoc array shapes and curly braces. Run `vendor/bin/pint --dirty --format agent` after PHP edits.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: module skeleton, wiring, assets and configuration.

- [X] T001 Create the Admin module skeleton directories: `app/Modules/Admin/Domain/Exceptions`, `app/Modules/Admin/Http/{Middleware,Navigation,Shell,Presenters,Icons,Livewire/Concerns,Views/components,Views/livewire,Lang/en,Lang/ar,Assets}`, plus `tests/Unit/Admin`, `tests/Feature/Admin/Primitives` and `tests/Feature/Admin/Fixtures`. Only `Domain` and `Http` are used under the module (plan, Structure Decision).
- [X] T002 Create `config/admin.php` returning `['timezone' => env('ADMIN_TIMEZONE', 'Africa/Cairo'), 'supported_locales' => ['en', 'ar'], 'rtl_locales' => ['ar']]`, with a PHPDoc header explaining each key (data-model.md, Configuration).
- [X] T003 Create `App\Modules\Admin\Http\AdminServiceProvider` in `app/Modules/Admin/Http/AdminServiceProvider.php`. Its `boot()` must:
  - `loadViewsFrom(__DIR__.'/Views', 'admin')`;
  - call `Blade::anonymousComponentPath(__DIR__.'/Views/components', 'admin')`;
  - `loadTranslationsFrom(__DIR__.'/Lang', 'admin')`;
  - call `Livewire::addNamespace('admin', classNamespace: 'App\\Modules\\Admin\\Http\\Livewire', classPath: __DIR__.'/Livewire', classViewPath: __DIR__.'/Views/livewire')`;
  - `loadRoutesFrom(__DIR__.'/routes.php')`.
  Leave clearly marked stubs for the registry binding (T053), Blaze (T064) and middleware (T046). Register it in `bootstrap/providers.php` (R-3).
- [X] T004 Create `app/Modules/Admin/Http/routes.php`. For now it holds only a `Route::middleware('web')->prefix('admin')->name('admin.')->group(...)` with an empty body and a comment that the gallery (T060) is the only route this feature adds, local only (R-13, FR-051).
- [X] T005 [P] Create `app/Modules/Admin/Http/Assets/admin.css` with `@import 'tailwindcss';`, `@source '../Views';`, and empty `@theme {}` and `:lang(ar) {}` blocks for T008. Create `app/Modules/Admin/Http/Assets/admin.js` as an empty ES module with section comments (dialogs, copy, toasts, date picker).
- [X] T006 Update `vite.config.js`:
  - add `app/Modules/Admin/Http/Assets/admin.css` and `app/Modules/Admin/Http/Assets/admin.js` to `laravel({ input })`;
  - add `bunny('Hanken Grotesk', { weights: [400, 500, 600, 700, 800] })` and `bunny('IBM Plex Sans Arabic', { weights: [400, 500, 600, 700] })` to `fonts` (switch the provider to `google()` if Bunny lacks either family);
  - keep `resources/css/app.css` and Instrument Sans untouched (R-6, R-17).
  Run `dev exec -- npm run build` and confirm the font files appear under `public/build/assets`.
- [X] T007 [P] Create the English translation files with a documented empty `return [];` in `app/Modules/Admin/Http/Lang/en/{shell,actions,forms,data,feedback,values,reason,gallery}.php`. Arabic mirrors are created in US2 (T049).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: tokens, icons, the base HTML document, and the architecture gates every story relies on.

**⚠️ No user-story work starts until this phase is complete.**

- [X] T008 Fill `app/Modules/Admin/Http/Assets/admin.css`:
  - `@theme` with every token in [contracts/tokens.md](./contracts/tokens.md): `--color-*` (exact hex), `--font-sans: 'Hanken Grotesk', 'IBM Plex Sans Arabic', system-ui, sans-serif`, `--font-mono`, `--radius-badge: 6px` / `control: 10px` / `tile: 14px` / `card: 18px` / `hero: 22px`, `--shadow-raised`, `--shadow-overlay`;
  - an `:lang(ar)` rule setting `letter-spacing: normal; text-transform: none` for all descendants, plus the Arabic heading weight 700 and line-height 1.35 (FR-035);
  - a comment on how a future `[data-theme="dark"]` block overrides the same variables (FR-005);
  - a `@media (prefers-reduced-motion: reduce)` rule disabling skeleton and spinner animation.
- [X] T009 [P] Create `App\Modules\Admin\Http\Icons\IconRegistry` in `app/Modules/Admin/Http/Icons/IconRegistry.php`. It maps each name to `array{paths: string, mirrors: bool}` for the 36 names in data-model.md (Icon registry), with 24-unit stroke paths taken from the design source Foundations icon board (copy the path data used on the artifact's boards). `mirrors: true` applies only to chevron-start, chevron-end, sign-out, trend-up, trend-down, external and refresh. `get(string $name)` throws `UnknownIcon` (`app/Modules/Admin/Http/Icons/UnknownIcon.php`) for an unknown name.
- [X] T010 Create `<x-admin::icon>` in `app/Modules/Admin/Http/Views/components/icon.blade.php`. Props: `name`, `size` (14|16|18|20|24, default 18), `label` (null = decorative). It renders inline `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">` from `IconRegistry`, with `aria-hidden="true"` unless labelled (`role="img"` + `aria-label`), and adds `rtl:-scale-x-100` when `mirrors` (R-5, FR-004, FR-032).
- [X] T011 [P] Create `<x-admin::ltr>` in `app/Modules/Admin/Http/Views/components/ltr.blade.php`, rendering `<bdi dir="ltr">{{ $slot }}</bdi>` (FR-033).
- [X] T012 Create the base document layout `<x-admin::layouts.document>` in `app/Modules/Admin/Http/Views/components/layouts/document.blade.php`:
  - `<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('admin.rtl_locales')) ? 'rtl' : 'ltr' }}" data-theme="light">`, the `title` prop, `@vite(['app/Modules/Admin/Http/Assets/admin.css', 'app/Modules/Admin/Http/Assets/admin.js'])`, `@livewireStyles`/`@livewireScripts`;
  - `<body class="bg-canvas text-ink font-sans [font-feature-settings:'tnum']">` — if the arbitrary-property class trips the T014 lint, put the `tnum` rule in `admin.css` instead;
  - the default slot.
  The shell (US3) wraps this. Primitives' feature tests render inside it.
- [X] T013 [P] Create `App\Modules\Admin\Http\Shell\OperatorPresence` in `app/Modules/Admin/Http/Shell/OperatorPresence.php`, a readonly DTO with fields `string $displayName`, `string $initial` ("One grapheme") and `string $statusKey` (a translation key) (data-model.md).
- [X] T014 Write `tests/Arch/AdminBackOfficeTest.php` (R-16, spec Invariants), covering:
  - (a) Livewire: every class extending `Livewire\Component` under `App\` is in `App\Modules\Admin\Http\Livewire` ("P-18 rule 3");
  - (b) views: no Admin Blade view exists in `resources/views` (`admin`, `livewire` or `components/admin` directories) ("P-18 rule 3");
  - (c) state: no public property of a class in `App\Modules\Admin\Http\Livewire` is typed as, or as a nullable or union containing, a subclass of `Illuminate\Database\Eloquent\Model` (checked by reflection) ("P-18 rule 5");
  - (d) RTL lint: a regex scan of every `*.blade.php` under `app/Modules/Admin/Http/Views` fails on the physical utilities `\b(-?m[lr]|p[lr]|left|right|text-(left|right)|border-[lr]|rounded-[lr]|rounded-(tl|tr|bl|br))-?` used as class tokens ("FR-031");
  - (e) token lint: the same scan fails on the arbitrary colour or length values `\[#[0-9a-fA-F]{3,8}\]`, `\[\d+(\.\d+)?(px|rem)\]` and `style="[^"]*(color|background|margin|padding)` ("FR-001").
  Each failure message names the rule and the file and line. Run it: it passes on the current tree.
- [X] T015 [P] Create `tests/Feature/Admin/Support/RendersAdmin.php`, a Pest helper trait or functions providing `renderAdmin(string $blade, array $data = [], string $locale = 'en'): string`. It sets the locale, renders through `$this->blade(...)` wrapped in `<x-admin::layouts.document title="test">`, and returns the HTML. Include `assertNoEasternDigits(string $html)`, which asserts no U+0660–U+0669 appears (R-9). Register it in `tests/Pest.php` for `Feature/Admin`.

**Checkpoint**: tokens compile, icons render, the base document carries `lang`/`dir`, and the arch gates pass on the tree.

---

## Phase 3: User Story 1 — Build a back-office screen from ready primitives (Priority: P1) 🎯 MVP

**Goal**: every primitive in FR-011 to FR-018, with value presenters and the reason prompt, usable with no extra styling.

**Independent Test**: the primitive feature tests render each family's documented props and states; `MoneyDisplayTest`, `TemporalDisplayTest`, `AuditReasonTest` and `ReasonPromptTest` pass; the arch lint stays green.

### Tests for User Story 1 (write first, confirm they fail)

- [X] T016 [P] [US1] `tests/Unit/Admin/MoneyDisplayTest.php`. Covers:
  - the en/ar outputs in [contracts/values-and-rtl.md](./contracts/values-and-rtl.md): `EGP 1,290.00`, `1,290 ج.م`, `1,290.50 ج.م`, `SAR 1,290.00`, `1,290 ر.س`, `AED`, `USD`, the unknown code `XYZ 1,290.00` / `1,290 XYZ`;
  - zero (`EGP 0.00` / `0 ج.م`) and negative with U+2212 (`−EGP 40.00`);
  - compact truncation (`EGP 1.2K`, `EGP 38.2M`, `38.2 مليون ج.م`, `1.2 مليار ج.م`);
  - large values `PHP_INT_MAX - 1` and `900719925474099312`, whose exact digits must survive (proving no float);
  - no U+0660–U+0669 in any output;
  - that the presenter rejects non-int input through its type.
- [X] T017 [P] [US1] `tests/Unit/Admin/TemporalDisplayTest.php`. Covers:
  - the calendar date `2026-09-25` unchanged under any default timezone;
  - the instant `2026-09-25T18:04:00Z` with `Africa/Cairo` → `25 Sep 2026, 21:04 EEST` (en) and `25 سبتمبر 2026، 21:04 EEST` (ar);
  - relative time under 7 days (`4 minutes ago` / `منذ 4 دقائق`), falling back to the absolute instant at ≥ 7 days;
  - a daylight-saving boundary instant showing the correct abbreviation;
  - no U+0660–U+0669 (FR-022, FR-023, P-14).
- [X] T018 [P] [US1] `tests/Unit/Admin/AuditReasonTest.php`. Covers: trims the value; throws `InvalidAuditReason` `blank` for `''`, `'   '` and `"\n\t"`; throws `too_long` for 501 characters; accepts exactly 500 multibyte Arabic characters, counted by `mb_strlen`.
- [X] T019 [P] [US1] `tests/Feature/Admin/Primitives/ActionsTest.php`. Covers:
  - `button`: each variant, size and state (the `loading` state has `aria-busy="true"` and a spinner; `disabled` has the `disabled` attribute; `href` renders `<a>`);
  - `icon-button` without `label` throws;
  - `link external` has `rel="noopener"` and hidden text;
  - `segmented` has `role="radiogroup"`;
  - `split-button` has `aria-haspopup="menu"`;
  - `menu`: `role="menu"`; a menu item with `disabled-reason` has `aria-disabled="true"` and `aria-describedby` pointing at the reason text; `opens-prompt` renders the trailing ellipsis.
- [X] T020 [P] [US1] `tests/Feature/Admin/Primitives/FormsTest.php`. Covers:
  - `field`: wires `for` and `aria-describedby` for hint and error; the required marker is present;
  - `input` states; email, number and money inputs carry `dir="ltr"`;
  - `money-input`: shows the major units and submits minor units in a hidden input;
  - `textarea` counter;
  - `select`: combobox roles (`role="combobox"`, `aria-expanded`, a `role="listbox"` option set) and a hidden input value;
  - `checkbox` indeterminate: `aria-checked="mixed"`;
  - `toggle`: `role="switch"` with `aria-checked`;
  - `radio-group`: `fieldset` + `legend`;
  - date and instant pickers: Western digits, `ltr` values, the instant hint showing the zone and UTC;
  - `search`: `role="search"`;
  - `form-section`: `aria-labelledby`;
  - `validation-summary`: `role="alert"`, links to `#field-id`, hidden when empty.
- [X] T021 [P] [US1] `tests/Feature/Admin/Primitives/DataTest.php`. Covers:
  - `table`: a real `<table>`; a sortable header button with `aria-sort`; the header checkbox is indeterminate on partial selection; selected rows are marked; `loading` renders skeleton rows and never the empty state; `empty` renders the empty-state title;
  - `table.filters`: remove buttons are labelled;
  - cursor `table.pagination`: `<nav>`, and "Newer" is disabled on the first page;
  - `description-list`: `<dl>`;
  - `stat-tile`: `delta meaning="bad" direction="up"` uses the over tone (the meaning, not the direction); `suppressed` shows `admin::data.suppressed` and no number; `loading` shows skeleton; the `ring` value appears in text;
  - `card`: a titled card is a `<section>`.
- [X] T022 [P] [US1] `tests/Feature/Admin/Primitives/FeedbackTest.php`. Covers:
  - `pill`: the five tones; `badge`: its tones, and the dot has hidden text;
  - `alert`: `role="status"` for info/success and `role="alert"` for warning/error;
  - `toast-stack`: has `aria-live`;
  - `empty-state`: its heading level;
  - `skeleton`: `aria-hidden`;
  - `dialog`: a native `<dialog>` with `aria-labelledby` and `aria-describedby`;
  - `confirm-dialog`: `role="alertdialog"`, and with `confirm-phrase` the confirm button renders disabled;
  - `slide-over`: a `<dialog>` with the end-edge class;
  - `reason-prompt`: renders the action-key title and consequence, the counter `0 / 500`, the attribution inside `<bdi dir="ltr">` for the code, preset chips, and confirm disabled when empty.
- [X] T023 [P] [US1] `tests/Feature/Admin/Primitives/NavigationTest.php`. Covers: underline and pill `tabs` (`role="tablist"`, a current tab with `aria-selected`); `breadcrumbs` (`<nav aria-label>`, the last item `aria-current="page"`); numbered `pagination` (`aria-current="page"`, ellipsis).
- [X] T024 [P] [US1] `tests/Feature/Admin/Primitives/ValueDisplayTest.php`. Covers:
  - `money`: `<data value="129000 EGP">` and compact `title`/`aria-label` with the full value;
  - `date` and `instant`: `<time datetime>`;
  - `relative-time`: `title` holds the absolute instant;
  - `identifier`: middle truncation `0192f4…f2b6d3`, full value in the copy target, mono, `dir="ltr"`;
  - `icon`: mirrored icons carry `rtl:-scale-x-100` and non-mirrored ones don't.
- [X] T025 [P] [US1] `tests/Feature/Admin/Fixtures/AuditedFixture.php`, a Livewire component (test-only, in the `Tests\Feature\Admin\Fixtures` namespace) using `RequiresReason`. It has a public `start(string $id)` that calls `$this->requireReason('fixture.approve', 'approve', [$id])`, a **protected** `approve(string $id, AuditReason $reason)` that appends to a public `array $calls` (scalars only), and a minimal inline view. Register it in the test with `Livewire::component()`.
- [X] T026 [US1] `tests/Feature/Admin/ReasonPromptTest.php`, using T025. Covers:
  - `confirmReason('')` and `confirmReason('   ')` add an error and leave `calls` empty with the prompt still open;
  - a valid reason calls `approve` once, with the trimmed reason, and closes the prompt;
  - a 501-character reason is refused;
  - calling `approve` directly from the client is rejected (protected);
  - `requireReason` targeting a public method, or one lacking an `AuditReason` parameter, throws `LogicException`;
  - `cancelReason` clears the pending state.

### Implementation for User Story 1

- [X] T027 [P] [US1] Create `App\Modules\Admin\Domain\AuditReason` (`app/Modules/Admin/Domain/AuditReason.php`) and `App\Modules\Admin\Domain\Exceptions\InvalidAuditReason` (with named constructors `blank()` and `tooLong()`). Rules: "Trimmed. Non-blank after trimming. At most 500 characters, counted by `mb_strlen`". Immutable, with a `value(): string` accessor. No framework imports (P-02). T018 passes.
- [X] T028 [P] [US1] Create `App\Modules\Admin\Http\Presenters\MoneyPresenter` (`app/Modules/Admin/Http/Presenters/MoneyPresenter.php`):
  - `format(int $minor, string $currency, string $locale): string`, `compact(int $minor, string $currency, string $locale): string` and `markupValue(int $minor, string $currency): string`;
  - **integer and string arithmetic only** (`intdiv`, `%`, `str_pad`, chunked grouping). Never `number_format`, `NumberFormatter`, `round` or any float;
  - an exponent/symbol table for EGP/SAR/AED/USD (ar symbols `ج.م`, `ر.س`, `د.إ`, `US$`), with fallback exponent 2 and the ISO code;
  - the en form puts the code first with two decimals; the ar form puts the number first, drops a `.00` fraction, and appends the symbol;
  - negatives use U+2212;
  - compact thresholds K/M/B (ar ألف/مليون/مليار), truncating to one decimal (R-8).
  T016 passes.
- [X] T029 [P] [US1] Create `App\Modules\Admin\Http\Presenters\TemporalPresenter` (`calendarDate(string $ymd)`, `instant(string $utcIso, string $timezone, string $locale)`, `relative(string $utcIso, CarbonInterface $now, string $locale, int $thresholdDays = 7)`) and `App\Modules\Admin\Http\Presenters\IdentifierPresenter` (`truncate(string $id, int $head = 6, int $tail = 6)`: values of 16 characters or fewer are returned whole). Use CarbonImmutable with `locale()`; formats `D MMM YYYY, HH:mm z` (en) and `D MMMM YYYY، HH:mm z` (ar). T017 passes.
- [X] T030 [US1] In `AdminServiceProvider`, bind `MoneyPresenter`, `TemporalPresenter` and `IdentifierPresenter` as singletons.
- [X] T031 [P] [US1] Fill `app/Modules/Admin/Http/Lang/en/{actions,forms,data,feedback,values,reason}.php` with every built-in string the primitives use: loading, cancel, confirm, close, copied, copy, required, optional, characters counter, remove filter, add filter, newer, older, previous, next, page, sort ascending/descending, select all, selected count, suppressed ("Fewer than 10"), opens in new tab, stored as minor units, stored UTC, timezone label, meaning labels (favourable/unfavourable), reason prompt copy (question, attribution, presets `reference_verified`, `amount_matches`, `screenshot_checked`, and the `actions.fixture.approve` title/consequence for tests), and confirm-phrase instructions.
- [X] T032 [P] [US1] Actions primitives in `app/Modules/Admin/Http/Views/components/`: `button.blade.php`, `link.blade.php`, `icon-button.blade.php`, `segmented.blade.php`, `split-button.blade.php`, `menu/menu.blade.php`, `menu/item.blade.php`, `menu/separator.blade.php` and `menu/heading.blade.php`. Follow the Actions table in contracts/components.md, including the `preview-state` prop (hover|pressed|focus) for the gallery. The menu uses Alpine `x-anchor` and APG keyboard handling (arrows, Home/End, Enter, Escape, typeahead). Include a `@blaze`-safe structure with no request-dependent memoization. T019 passes.
- [X] T033 [P] [US1] Forms primitives in `app/Modules/Admin/Http/Views/components/`: `field`, `input`, `number`, `money-input`, `textarea`, `select`, `checkbox`, `radio-group`, `toggle`, `date-picker`, `instant-picker`, `search`, `form-section`, `validation-summary` (`.blade.php` each), per the Forms table.
  - `select` is an APG select-only combobox with `x-anchor` and a hidden input.
  - `date-picker` and `instant-picker` render their own Alpine grid, with Western digits and month names from `__()`, never `<input type="date">` (R-10).
  - `money-input` converts between major text and minor-unit integer in Alpine using string maths.
  T020 passes.
- [X] T034 [P] [US1] Data primitives in `app/Modules/Admin/Http/Views/components/`: `table/table.blade.php`, `table/column.blade.php`, `table/row.blade.php`, `table/cell.blade.php`, `table/filters.blade.php`, `table/pagination.blade.php`, `description-list.blade.php`, `stat-tile.blade.php` and `card.blade.php`, per the Data table.
  - The table renders only what it is given. Sort and selection intents are `wire:click` targets passed in by attributes.
  - `stat-tile` colours its delta from `meaning` (good → positive-text, bad → over-text, neutral → ink-2), never from `direction`.
  - Ring and sparkline are inline SVG and mirror via `rtl:-scale-x-100`.
  T021 passes.
- [X] T035 [P] [US1] Feedback primitives in `app/Modules/Admin/Http/Views/components/`: `badge`, `pill`, `alert`, `toast-stack`, `empty-state`, `skeleton`, `dialog`, `confirm-dialog`, `modal` (an alias wrapping `dialog` size md), `slide-over` and `reason-prompt` (`.blade.php` each), per the Feedback table and [contracts/reason-prompt.md](./contracts/reason-prompt.md).
  - Dialogs are native `<dialog>` opened with `showModal()` through Alpine, with `x-trap` and focus returned to the opener. `confirm-dialog`'s initial focus is cancel; `reason-prompt`'s is the textarea (R-10).
  - `toast-stack` listens for the `admin-toast` browser event.
  T022 passes.
- [X] T036 [P] [US1] Navigation primitives in `app/Modules/Admin/Http/Views/components/`: `tabs.blade.php`, `breadcrumbs.blade.php` and `pagination.blade.php` (numbered), per the Navigation table. The breadcrumb separator is `<x-admin::icon name="chevron-end">`. T023 passes.
- [X] T037 [P] [US1] Value primitives in `app/Modules/Admin/Http/Views/components/`: `money.blade.php`, `date.blade.php`, `instant.blade.php`, `relative-time.blade.php` and `identifier.blade.php`. They call the T028/T029 presenters (resolved from the container) and use the markup in contracts/values-and-rtl.md (`<data>`, `<time>`, `<bdi dir="ltr">`). `instant`'s timezone defaults to `config('admin.timezone')`. `identifier` has an Alpine copy button that shows `admin::values.copied` for 2 s. T024 passes.
- [X] T038 [US1] Implement the Alpine helpers in `app/Modules/Admin/Http/Assets/admin.js`, registered on `alpine:init`:
  - `adminDialog` (open, close, opener memory);
  - `adminCopy` (Clipboard API with a fallback);
  - `adminToasts` store (max 3 visible; errors sticky; others dismiss after 6 s, paused on hover or focus; optional undo calls the Livewire method named in the event);
  - `adminDatePicker` (grid building, arrow/PageUp/PageDown keys, Western digits);
  - `adminMoneyInput` (string-based major↔minor conversion).
  No external imports: Alpine comes from Livewire.
- [X] T039 [US1] Create the trait `App\Modules\Admin\Http\Livewire\Concerns\RequiresReason` (`app/Modules/Admin/Http/Livewire/Concerns/RequiresReason.php`) per [contracts/reason-prompt.md](./contracts/reason-prompt.md):
  - public scalar state `?string $reasonAction`, `?string $reasonMethod`, `array $reasonArguments` (scalars only), `bool $reasonOpen`;
  - `requireReason()` checks by reflection that the target is **protected** and that its last parameter is typed `AuditReason`, throwing `LogicException` otherwise;
  - `confirmReason(string $reason)` builds the `AuditReason` (on `InvalidAuditReason` it adds a `reason` validation error and keeps the prompt open), calls `$this->authorizeReason($action)` (a hook, no-op by default), invokes the method with `reason:`, then resets;
  - `cancelReason()` resets.
  T026 passes.

**Checkpoint**: US1 complete. A developer can compose any screen from primitives, and audited actions cannot run without a reason.

---

## Phase 4: User Story 2 — Work in Arabic, right to left (Priority: P1)

**Goal**: an Arabic, right-to-left rendering of every primitive and the base document, a session-scoped language switch, and full Arabic translations.

**Independent Test**: `DirectionTest`, `LocaleSwitcherTest` and `TranslationCompletenessTest` pass. The value tests' Arabic cases pass. The arch RTL lint is green.

### Tests for User Story 2

- [X] T040 [P] [US2] `tests/Feature/Admin/DirectionTest.php`. Covers:
  - the document renders `dir="rtl" lang="ar"` when the locale is ar and `dir="ltr" lang="en"` when it is en;
  - an Accept-Language `ar-EG,ar;q=0.9` request with no session resolves to ar; `fr-FR` resolves to en; a session `admin.locale=ar` beats the header;
  - an invalid session value falls back through the header and then en.
  Use a test-only route registered in the test with the `ResolveAdminLocale` middleware.
- [X] T041 [P] [US2] `tests/Feature/Admin/LocaleSwitcherTest.php`. Covers: `Livewire::test(LocaleSwitcher::class)->call('switchTo', 'ar')` sets `session('admin.locale')` to `ar` and redirects to the current URL; `switchTo('fr')` is refused with a validation error and no session change; public properties are scalars only.
- [X] T042 [P] [US2] `tests/Feature/Admin/TranslationCompletenessTest.php`. Covers:
  - (a) for every file in `app/Modules/Admin/Http/Lang/en`, the same file exists in `ar` with the identical set of dotted keys, and vice versa;
  - (b) every `__('admin::…')`, `@lang('admin::…')` and `trans('admin::…')` key literal found by scanning `app/Modules/Admin/Http` (views and PHP) exists in both locales;
  - (c) no Arabic value contains U+0660–U+0669 (FR-036, P-14).
- [X] T043 [P] [US2] Extend `tests/Feature/Admin/Primitives/ValueDisplayTest.php` with Arabic cases:
  - `money` in ar renders `1,290 ج.م` with only the number inside `<bdi dir="ltr">`;
  - identifier, date, instant and email values sit inside `<bdi dir="ltr">`;
  - the reason-prompt attribution code is isolated;
  - `assertNoEasternDigits` holds on every rendered primitive family in ar (FR-033, FR-034).

### Implementation for User Story 2

- [X] T044 [P] [US2] Create `App\Modules\Admin\Http\Middleware\ResolveAdminLocale` (`app/Modules/Admin/Http/Middleware/ResolveAdminLocale.php`). Order: `session('admin.locale')` if it is in `config('admin.supported_locales')`, then the first of those found in the request's `getLanguages()` (compare the primary subtag), then `en`. It calls `App::setLocale()` and `Carbon::setLocale()` (R-7).
- [X] T045 [P] [US2] Create the Livewire `App\Modules\Admin\Http\Livewire\LocaleSwitcher` (`app/Modules/Admin/Http/Livewire/LocaleSwitcher.php`) with view `app/Modules/Admin/Http/Views/livewire/locale-switcher.blade.php`:
  - public `string $locale` (a scalar);
  - `switchTo(string $locale)` validates `in:` the supported locales, writes the session, and `redirect(request()->header('Referer', url()->current()))`;
  - the view renders `<x-admin::segmented>` with the options "English" and "العربية", each shown in its own language regardless of the active locale.
- [X] T046 [US2] In `AdminServiceProvider`, apply `ResolveAdminLocale` to the Admin route group (update `routes.php` middleware to `['web', ResolveAdminLocale::class]`) and register it with `Livewire::addPersistentMiddleware([ResolveAdminLocale::class])`, so component updates keep the locale.
- [X] T047 [US2] Audit every primitive from T032–T037 in Arabic, and fix it in its own file:
  - start/end icons sit on the correct side;
  - `chevron-start`/`chevron-end` are used instead of left/right;
  - the `slide-over` enters from the end edge;
  - `toggle` knob travel uses `justify-end`;
  - menus anchor at start or end;
  - the date-picker grid order mirrors while its value text stays `ltr`;
  - the table `align` start/end maps logically;
  - stat-tile rings and trend icons mirror.
  Cross-check against the Arabic screen boards of the design source. Keep T014 green.
- [X] T048 [P] [US2] Arabic type rules in `app/Modules/Admin/Http/Assets/admin.css`: confirm that the `:lang(ar)` block (T008) resets tracking and case on overlines and headings, that h1 uses weight 700 with line-height 1.35 and body line-height 1.6, and that sidebar items are 40 px tall in ar ([contracts/tokens.md](./contracts/tokens.md) Density).
- [X] T049 [P] [US2] Create `app/Modules/Admin/Http/Lang/ar/{actions,forms,data,feedback,values,reason}.php` with the Arabic translations of every key from T031. Use the design's Arabic copy where it exists (for example "الأحدث"/"الأقدم", "نُسخ", "أقل من 10", "رفض…", "موافقة…"), with Western digits only.

**Checkpoint**: US1 and US2 together give the full bilingual primitive set.

---

## Phase 5: User Story 3 — A shell every screen plugs into (Priority: P2)

**Goal**: the authenticated layout with sidebar, top bar, page header and navigation registry. Operator inputs are ready for the sign-in spec.

**Independent Test**: `NavigationRegistryTest` and `ShellTest` pass. A fixture entry registered in a test renders with badge and `aria-current`, with no shell edit.

### Tests for User Story 3

- [X] T050 [P] [US3] `tests/Feature/Admin/NavigationRegistryTest.php`. Covers:
  - the default groups `operate` (10) and `configure` (20);
  - `add()` orders entries by `order` then `key`;
  - a duplicate key throws `DuplicateNavigationEntry`; an unknown group throws `UnknownNavigationGroup`; an unknown icon throws `UnknownIcon`;
  - the current entry matches an exact route name or `route.` prefix, and the longest match wins;
  - `null`/`0` badges are hidden; a throwing badge closure is caught, reported via `report()`, and hidden;
  - a fixture entry registered from a test-only provider renders inside the shell under its group with its badge and `aria-current="page"` (SC-006).
- [X] T051 [P] [US3] `tests/Feature/Admin/ShellTest.php`. Covers:
  - the shell renders a skip link, `<nav aria-label>` sidebar, top bar, `<main id="content">` and toast stack;
  - breadcrumbs, and the title/subtitle/`actions`/`status` slots;
  - the environment badge shows `admin::shell.environment.production` in the over tone when env is production, and neutral for local/staging;
  - the operator block shows `displayName` and `initial` from an `OperatorPresence`; with null `operator` it shows only the language switcher, and with null `sign-out-url` it hides sign-out;
  - with an empty registry the sidebar shows `admin::shell.navigation_empty` (FR-010);
  - the product name comes from `config('app.name')`.

### Implementation for User Story 3

- [X] T052 [P] [US3] Create `NavigationGroup`, `NavigationEntry` (readonly; fields exactly as data-model.md, including "`badge` `?Closure(): ?int`"), `DuplicateNavigationEntry`, `UnknownNavigationGroup` and `NavigationRegistry` in `app/Modules/Admin/Http/Navigation/`. The registry has `group()`, `add()` and `groups(?string $currentRoute = null): list<array{group: NavigationGroup, entries: list<array{entry: NavigationEntry, current: bool, badge: ?int}>}>`. It validates the icon through `IconRegistry`, and registers `operate` and `configure` in its constructor.
- [X] T053 [US3] In `AdminServiceProvider`, bind `NavigationRegistry` as a singleton (replace the T003 stub).
- [X] T054 [P] [US3] Create the shell parts in `app/Modules/Admin/Http/Views/components/shell/`, per board 06 and [contracts/shell-and-navigation.md](./contracts/shell-and-navigation.md):
  - `sidebar.blade.php`: brand from `config('app.name')` plus `admin::shell.back_office`, grouped nav, and the operator block with the `livewire:admin::locale-switcher` and sign-out;
  - `nav-item.blade.php`: icon, label, badge, current state with `aria-current`;
  - `topbar.blade.php`: breadcrumbs, the `search` and `notifications` slots, and the environment badge using `pill`;
  - `page-header.blade.php`: title, subtitle, `status` and `actions` slots.
- [X] T055 [US3] Create `<x-admin::layouts.shell>` in `app/Modules/Admin/Http/Views/components/layouts/shell.blade.php`. It wraps `layouts.document` and takes the props `title`, `subtitle`, `breadcrumbs`, `operator` (`?OperatorPresence`), `sign-out-url` and `navigation` (null → `NavigationRegistry::groups(request()->route()?->getName())`), with the slots default, `actions`, `status`, `header`, `search` and `notifications`. It renders the skip link, sidebar (248 px, `border-e`), top bar (60 px), `<main id="content">` (max width 1200 px) and `<x-admin::toast-stack>`.
- [X] T056 [P] [US3] Fill `app/Modules/Admin/Http/Lang/en/shell.php` and `app/Modules/Admin/Http/Lang/ar/shell.php`: groups (operate/التشغيل, configure/الإعدادات), primary navigation label, breadcrumb label, skip link, back office (لوحة الإدارة), environments (local/staging/production → محلي/تجريبي/الإنتاج), navigation_empty, the operator status `two_factor_on`, sign out (تسجيل الخروج), and language labels.

**Checkpoint**: later features can register sections and render pages in the shell.

---

## Phase 6: User Story 4 — Review the design without building a feature (Priority: P2)

**Goal**: a local-only gallery showing every primitive in every state, in both languages, inside the shell.

**Independent Test**: `GalleryTest`, `GalleryAccessTest` and `AdminRouteSurfaceTest` pass.

### Tests for User Story 4

- [X] T057 [P] [US4] `tests/Feature/Admin/GalleryTest.php`. In local env:
  - `GET /admin/_gallery` returns 200, rendered in the shell;
  - it contains a section heading for each of Tokens, Actions, Forms, Data, Feedback, Navigation, Values and Reason prompt;
  - every component name from contracts/components.md renders at least once, with each listed state present (assert through `data-gallery-item="button.primary.loading"`-style markers, one per state);
  - with `session(['admin.locale' => 'ar'])` the page renders `dir="rtl"` and passes `assertNoEasternDigits`;
  - the page performs no database query (`DB::listen` counts 0).
- [X] T058 [P] [US4] `tests/Feature/Admin/GalleryAccessTest.php`. With the environment set to `production`, then `staging`, and routes reloaded (re-require the Admin routes via a fresh app or `$this->refreshApplication()` after `app()->detectEnvironment(fn () => 'production')`), `GET /admin/_gallery` returns 404 (FR-041, SC-008).
- [X] T059 [P] [US4] `tests/Feature/Admin/AdminRouteSurfaceTest.php`. In local, the only route whose URI starts with `admin` is `admin/_gallery` (named `admin.gallery`). In production there is none. This covers FR-051 and the spec invariant "The local gallery is the only route registered under `/admin` until the sign-in spec adds the operator guard".

### Implementation for User Story 4

- [X] T060 [US4] Register the gallery in `app/Modules/Admin/Http/routes.php`: inside the group, `if (app()->environment('local')) { Route::livewire('/_gallery', Gallery::class)->name('gallery'); }`. Nothing else (R-13).
- [X] T061 [US4] Create the Livewire `App\Modules\Admin\Http\Livewire\Gallery` (`app/Modules/Admin/Http/Livewire/Gallery.php`), full page using `<x-admin::layouts.shell>`:
  - public scalar state only (`string $section = 'tokens'` for an in-page jump);
  - it passes a sample `OperatorPresence('Mariam S.', 'M', 'admin::shell.operator.two_factor_on')` (ar: 'مريم س.', 'م'), `sign-out-url="#"`, and sample `navigation` built from a `NavigationRegistry` instance local to the gallery, **not** the app singleton;
  - it uses `RequiresReason` with a protected `sampleApprove(AuditReason $reason)` that only dispatches an `admin-toast`, to demonstrate the prompt.
- [X] T062 [US4] Create the gallery view `app/Modules/Admin/Http/Views/livewire/gallery.blade.php`:
  - sections in board order;
  - each primitive in every state from contracts/components.md (using `preview-state` for hover and pressed), each wrapped in an element with `data-gallery-item="{component}.{variant}.{state}"`;
  - a token section rendering swatches from the theme utilities, type-scale samples and radii;
  - value samples: `129000 EGP` in en/ar, compact `3820000000 EGP`, `2026-09-25`, an instant `2026-09-25T18:04:00Z`, identifier `0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3`;
  - a working reason-prompt demo;
  - sample text from `admin::gallery.*`, each block labelled "Sample"/"نموذج".
- [X] T063 [P] [US4] Fill `app/Modules/Admin/Http/Lang/en/gallery.php` and `app/Modules/Admin/Http/Lang/ar/gallery.php` with section titles, the sample label, and invented sample strings (plan names, emails, claim IDs as in the design boards). The Arabic uses Western digits.

**Checkpoint**: a reviewer can open `/admin/_gallery` locally and check the whole system in both languages.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T064 In `AdminServiceProvider`, enable Blaze compile-only for primitives: `Blaze::optimize()->in(__DIR__.'/Views/components', compile: true, memo: false, fold: false)` (R-15). Run the whole Admin suite with `BLAZE_ENABLED=true` and `false`, which must be equal. Mark any primitive that breaks under compile with `@unblaze` and note it in `contracts/components.md`. **Outcome**: compile-only broke 90 of 139 primitive tests (Blaze 1.0.18 leaves required `@props` undefined), so Blaze stays off; recorded in research R-15 as a follow-up.
- [X] T065 [P] Performance check: in local, measure `/admin/_gallery` server render time (with `dev artisan route:cache` off). It must be under 1 s (plan, Performance Goals). If not, profile with `BLAZE_DEBUG=true` and fix the hot primitive.
- [X] T066 [P] Update `docs/engineering-conventions.md` with a short "Back office (Admin module)" section:
  - where primitives, Livewire classes, views, lang and assets live;
  - the `x-admin::` prefix, and the logical-utility and token lint rules;
  - how a feature registers a navigation entry;
  - how audited actions use `RequiresReason` (protected method with an `AuditReason` parameter).
  Link [contracts/](./contracts/).
- [X] T067 [P] Record the sibling sign-in spec: create `specs/briefs/D02-operator-sign-in.md`, whose Description is operator sign-in with email and password plus the time-based second factor, ten single-use recovery codes, two-operator re-enrolment, the first-operator artisan command, and binding `OperatorPresence` and the sign-out URL into the D1 shell (spec FR-051, security baseline "Operator access"). Add a D2 row to `specs/briefs/README.md` ordered after D1 and before B11 and B12, noting "one-time-code library needs approval, or in-house RFC 6238".
- [X] T068 Run `dev exec -- vendor/bin/pint --dirty --format agent`, then `dev exec -- composer test` (Pint test, Larastan level 8, arch, suite). Fix any level-8 findings in the new classes, which need array shapes on `NavigationRegistry::groups()` and presenter return types.
- [X] T069 Run [quickstart.md](./quickstart.md) sections 1–3. Seed each violation in the "gates bite" table, confirm the named test fails, and revert. **Outcome**: the physical-direction and token lints were seeded and bit; the other rows are covered by tests that assert the failure directly (route surface, public audited method, locked state, translation completeness).
- [ ] T070 Run the visual fidelity review in [quickstart.md](./quickstart.md) §4 against design source version `1790356228-859b` in English and Arabic, keyboard only and with a contrast spot-check. Record the results, and every nearest-state substitution, in the pull-request description.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (T001–T007)**: T003 depends on T001. T006 depends on T005. The rest are independent.
- **Foundational (T008–T015)**: depends on Setup. T010 depends on T009. T012 depends on T008 and T010.
- **US1 (T016–T039)**: depends on Foundational.
- **US2 (T040–T049)**: depends on Foundational. T047 and T043 depend on US1's primitives (T032–T037). T045 depends on T032 (`segmented`).
- **US3 (T050–T056)**: depends on Foundational. T054 needs `pill`, `breadcrumbs` and `badge` from US1 (T035, T036) and the `LocaleSwitcher` from US2 (T045).
- **US4 (T057–T063)**: depends on US1, US2 and US3, since it shows everything.
- **Polish (T064–T070)**: depends on all stories.

### Story completion order

`Setup → Foundational → US1 → US2 → US3 → US4 → Polish`. US2's tests T040–T042 and T044 can run alongside US1. The shell's registry (T050, T052, T053) can also run alongside US1.

### Within each story

Tests first and failing, then value objects and presenters, then components, then wiring, then green.

## Parallel Execution Examples

**US1 tests together:**

```text
T016 MoneyDisplayTest   T017 TemporalDisplayTest   T018 AuditReasonTest
T019 ActionsTest        T020 FormsTest             T021 DataTest
T022 FeedbackTest       T023 NavigationTest        T024 ValueDisplayTest   T025 AuditedFixture
```

**US1 implementation together** (separate files):

```text
T027 AuditReason   T028 MoneyPresenter   T029 Temporal/IdentifierPresenter   T031 en lang
T032 Actions       T033 Forms            T034 Data                          T035 Feedback
T036 Navigation    T037 Values
```

**US2:** T040, T041, T042 and T043 (tests), then T044, T045, T048 and T049 together; T047 last.

**US3:** T050 and T051 (tests), then T052, T054 and T056 together; T053 and T055 follow.

**US4:** T057, T058 and T059 (tests), then T060 → T061 → T062 alongside T063.

## Implementation Strategy

### MVP (US1 only)

Phases 1–3 deliver every primitive, bilingual-ready (English text) and RTL-safe by construction because of the lint rules. That is enough for a developer to start composing screens. Stop and validate with the US1 tests and the arch gate.

### Incremental delivery

1. US1: primitives (MVP).
2. US2: Arabic text and the switcher. The back office is now fully bilingual.
3. US3: the shell and registry. Features can now plug in pages.
4. US4: the gallery. Design review without a feature.
5. Polish: Blaze, docs, the D2 brief, the full gate, and the fidelity review.

Each checkpoint leaves `composer test` green.
