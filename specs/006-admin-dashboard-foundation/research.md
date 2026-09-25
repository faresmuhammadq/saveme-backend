# Research: Admin Dashboard Foundation

Phase 0 of [plan.md](./plan.md). Each entry records a decision, why, and what was set aside. Evidence gathered on 2026-09-25 against the installed versions: Livewire 4.4.5, livewire/blaze 1.0.18, Tailwind 4, laravel-vite-plugin 3.2.0, PHP 8.5 with `intl`.

## R-1 Ready-made kit or in-house primitives

- **Decision**: build the primitives in-house. No new dependency.
- **Rationale**: the design source is bespoke. It has its own token set, contrast-adjusted greys, status tones, the reason prompt, suppressed stat tiles and the money/instant value displays. A kit would have to be re-skinned component by component and would still lack half the catalogue. Owning the markup is also the only way to guarantee FR-031 to FR-033 (mirroring and left-to-right isolation) for every part. Quality Gate 6 forbids a new dependency without approval, and building in-house needs none.
- **Alternatives**: Flux (free tier): its own visual language, several listed primitives are paid-tier, and it is a dependency needing approval. Blade UI Kit and blade-icons: they cover a fraction of the catalogue and are still dependencies. The owner can overrule at `/speckit-tasks` time. The component contracts in [contracts/components.md](./contracts/components.md) are kit-agnostic, so a later swap stays local.

## R-2 What is a Blade component and what is a Livewire component

- **Decision**: every primitive is an **anonymous Blade component** rendered as `<x-admin::button>` and so on. Interactivity inside a primitive (menus, listbox, date picker, dialog open/close, copy) is Alpine, which Livewire 4 already bundles. **Livewire components** exist only where server state lives: the full-page `Gallery`, the `LocaleSwitcher` in the operator block, and the `RequiresReason` trait that later screens mix into their own components. Data-table state (sort, filters, cursor) belongs to the feature component that owns the query. The table primitive only renders what it is handed and emits intents (`wire:click` targets passed in as props).
- **Rationale**: P-18 rule 5 is about what a Livewire component serialises, and a stateless Blade primitive serialises nothing. Keeping primitives stateless makes them cheap, testable with a plain render, and usable inside any later Livewire screen.
- **Alternatives**: every primitive a Livewire component (nested component overhead, and serialised state per button); class-based Blade components. The latter was rejected because a class namespace and an anonymous path cannot reliably share the `admin` prefix, and logic-bearing displays are served better by plain presenter classes (R-8, R-9).

## R-3 Where the files live and how they are registered

- **Decision**: one provider, `App\Modules\Admin\Http\AdminServiceProvider`, added to `bootstrap/providers.php`. It:
  - registers the view namespace `admin` from `app/Modules/Admin/Http/Views` (`loadViewsFrom`);
  - registers anonymous components from `app/Modules/Admin/Http/Views/components` under the `admin` prefix (`Blade::anonymousComponentPath`);
  - registers the translation namespace `admin` from `app/Modules/Admin/Http/Lang/{en,ar}` (`loadTranslationsFrom`);
  - registers the Livewire namespace `admin` for classes in `App\Modules\Admin\Http\Livewire` with views in `admin::livewire` (`Livewire::addNamespace`, confirmed in `vendor/livewire/livewire/src/LivewireManager.php`);
  - loads the Admin routes file, which in this feature declares only the local gallery (R-13);
  - binds the `NavigationRegistry` singleton (R-12);
  - tells Blaze to compile the primitives path (R-15).
- **Rationale**: this is P-18 rule 3 made literal: nothing lands in `resources/views` or `app/Livewire`. Every file sits under one of the four layer directories that `tests/Arch/ModuleBoundariesTest.php` already polices.
- **Alternatives**: extending `app/Providers/ModuleServiceProvider` to discover per-module providers. That is a bigger convention change than one feature needs; revisit when a second module ships a provider.

## R-4 Design tokens

- **Decision**: tokens live once, in `app/Modules/Admin/Http/Assets/admin.css`, as Tailwind 4 `@theme` variables. These are the exact hex values from the design source's Foundations board, including the contrast-adjusted text tones (`--color-ink-3: #6F7886`, `--color-positive-text: #2C7A5B`, `--color-over-text: #A9503A`, `--color-pending-text: #8F5E12`, `--color-field: #8A93A2`). Tailwind generates the utilities (`bg-surface`, `text-ink-2`, `rounded-card`, `shadow-overlay`) from them. A future dark theme overrides the same variables under `[data-theme="dark"]`, and no primitive changes (FR-005).
- **Enforcement**: an Arch test scans `app/Modules/Admin/Http/Views` for arbitrary colour or size values (`[#…]`, `[…px]`, inline `style="color:` and so on) and fails on any hit. That is FR-001.
- **Alternatives**: a PHP token array rendered into CSS (a second source of truth); CSS variables without `@theme` (loses the generated utilities, so primitives would write `[var(--…)]` everywhere).

## R-5 Right-to-left

- **Decision**:
  1. The shell's `<html>` carries `lang` and `dir` from the active locale (FR-030).
  2. Primitives use **logical utilities only**: `ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`, `text-end`, `border-s`, `border-e`, `rounded-s`, `rounded-e`. An Arch lint test forbids the physical forms (`ml-`, `mr-`, `pl-`, `pr-`, `left-`, `right-`, `text-left`, `text-right`, `border-l`, `border-r`, `rounded-l`, `rounded-r`) in Admin views (FR-031).
  3. The icon registry marks each icon `mirrors: true|false`. Mirroring icons get `rtl:-scale-x-100` (FR-032).
  4. Value primitives (identifier, email, reference, code, date, instant, one-time-code input) wrap their output in `<bdi dir="ltr">`. A general `<x-admin::ltr>` is provided for other inline values (FR-033).
  5. Charts and rings mirror through the same `rtl:` variant, and time axes read start→end in document order (FR-031).
- **Evidence**: the six Arabic screen boards in the design source were built with exactly these rules. The RTL rules note on the canvas records them.
- **Alternatives**: a separate RTL stylesheet (two sources of truth); relying on reviewers to catch physical utilities (the lint test is cheaper and permanent).

## R-6 Fonts

- **Decision**: serve Hanken Grotesk (400–800) and IBM Plex Sans Arabic (400–700) through the already-installed `laravel-vite-plugin` fonts feature. It downloads at build time and emits the files as local assets (`emitFontAssets` in `dist/index.js`), so no third-party font request reaches the operator's browser. The font stack is `'Hanken Grotesk', 'IBM Plex Sans Arabic', system-ui, sans-serif`: Arabic glyphs fall through to Plex, while numerals and Latin stay in Hanken (FR-035). A `:lang(ar)` rule resets `letter-spacing` and `text-transform` (FR-035).
- **Rationale**: this needs no new dependency, keeps fonts self-hosted, and gives numeral parity between languages.
- **Alternatives**: a Google Fonts `<link>` (a third-party request per page); committing font files by hand (the plugin already automates it).

## R-7 Language selection

- **Decision**: session key `admin.locale`. Middleware `ResolveAdminLocale` on the Admin route group sets `App::setLocale` in this order: session value, then the first of `ar`/`en` in `Accept-Language`, then `en` (FR-037). It is registered as Livewire persistent middleware, so component update requests keep the locale. `LocaleSwitcher` (Livewire) writes the session and reloads the page, so `dir` flips everywhere at once. Translations use the `admin::` namespace, with one file per concern (`shell.php`, `actions.php`, `forms.php`, `data.php`, `feedback.php`, `values.php`, `reason.php`, `gallery.php`), in both `en` and `ar`.
- **Alternatives**: a locale segment in the URL (it pollutes every later route and is unnecessary for an internal tool); a per-operator preference (waits for operator accounts, per the spec's assumption).

## R-8 Money display without floats

- **Decision**: `App\Modules\Admin\Http\Presenters\MoneyPresenter` formats an `int $minor` plus an ISO 4217 code with **integer and string arithmetic only**. It splits with `intdiv` and `%` by the currency exponent, groups the digits by string chunking, and never calls `number_format` or `NumberFormatter` (both take floats). A small exponent-and-symbol table covers EGP, SAR, AED and USD. Unknown codes fall back to exponent 2 and show the ISO code (the spec's edge case). English renders `EGP 1,290.00`; Arabic renders `1,290 ج.م`, with a whole-unit display when the fraction is zero, as drawn. The compact form (`1.28M`, `38.2 مليون`) also uses integer maths, one decimal truncated. Negative values use the true minus sign (U+2212).
- **Rationale**: this is P-03 and FR-020 exactly. The unit test feeds `PHP_INT_MAX`-adjacent values, which would lose precision through a float.
- **Later**: when the Shared kernel's `Money` value object exists, the presenter gains an overload taking it. The formatting rules stay here, because they are presentation.
- **Alternatives**: `NumberFormatter::formatCurrency` (float input, and locale-dependent digits); Brick\Money (a dependency).

## R-9 Dates, instants, relative time

- **Decision**: Carbon, already present. Verified in the container: `locale('ar')` produces Western digits and Arabic month names (`25 سبتمبر 2026، 21:04 EEST`), and `diffForHumans` gives `قبل 4 دقائق`. A calendar date is parsed as a plain `Y-m-d` string with no timezone attached (FR-022). Instants convert from UTC to the operator timezone. Until operator accounts exist that timezone is `config('admin.timezone')`, defaulting to `Africa/Cairo`, and the zone abbreviation is always printed (FR-023). Relative time applies under seven days, with the absolute instant in `title` and in an accessible label (FR-023).
- **Guard**: `TemporalDisplayTest` asserts that no Eastern Arabic-Indic digit (U+0660–U+0669) appears in any Arabic output, so a future Carbon locale change can't regress P-14.
- **Alternatives**: `IntlDateFormatter` (its digits depend on the locale variant, and `ar_EG` gives Eastern digits).

## R-10 Overlays, menus and pickers

- **Decision**: dialogs (modal, confirmation, reason prompt, slide-over) use the native `<dialog>` element with `showModal()`. That gives the top layer, an inert background and Escape for free. Alpine's `x-trap`, bundled in Livewire 4 (verified in `livewire.js`), holds focus, and the opener element is recorded and refocused on close. Menus, the select listbox and the date picker follow the WAI-ARIA Authoring Practices patterns (menu button, select-only combobox, dialog date picker), positioned with Alpine's bundled `x-anchor`. The date picker renders its own grid, because a native `<input type="date">` shows browser-locale digits, which breaks FR-034.
- **Alternatives**: a native `<select>` (it cannot show the design's open state or guarantee Western digits across browsers); third-party popover libraries (dependencies).

## R-11 Reason prompt contract

- **Decision**:
  - `App\Modules\Admin\Domain\AuditReason` is a value object: trimmed, non-blank, at most 500 characters. Its constructor throws on anything else.
  - `App\Modules\Admin\Http\Livewire\Concerns\RequiresReason` is a trait for Livewire components. `$this->requireReason('claim.approve', 'approve', [$claimId])` opens the prompt with translated copy for that action key. On confirm the trait builds the `AuditReason`, re-authorizes, and calls `approve($claimId, $reason)`.
  - An audited method is **protected**, so Livewire won't dispatch it from the browser, and its signature takes `AuditReason`. The only path to it is `confirmReason`, and the type system makes "run without a reason" impossible (FR-018).
  - The prompt primitive, `<x-admin::reason-prompt>`, shows the attribution line from a prop (operator name and action key), since writing the `admin_action` entry is the later feature's job through Identity's audit service.
- **Rationale**: this makes the invariant structural instead of a convention. `ReasonPromptTest` drives a fixture component whose audited method records calls, and proves a blank reason never reaches it.
- **Alternatives**: a middleware that checks for a reason field (easily bypassed by a component method called directly).

## R-12 Navigation registry

- **Decision**: `App\Modules\Admin\Http\Navigation\NavigationRegistry` is a container singleton. Groups are declared with a key, a translation key and an order (`operate`, `configure` ship with the shell). A feature's provider calls `->add(new NavigationEntry(key, group, labelKey, icon, route, order, badge: ?Closure))`. A duplicate key throws `DuplicateNavigationEntry` at boot. The current entry is resolved by matching the current route name against the entry's route prefix, and the shell renders it with `aria-current="page"`. Badge closures run at render and must return `?int`. They will call query services when real features arrive.
- **Alternatives**: a config file (features would have to edit shared config, breaking FR-007); attribute discovery (magic, harder to test).

## R-13 Gallery exposure

- **Decision**: the Admin routes file registers `/admin/_gallery` (full-page Livewire `Gallery`) only inside `if (app()->environment('local'))`. In every other environment the route does not exist, so the framework's own not-found response applies (FR-041). `AdminRouteSurfaceTest` asserts two things. First, in `local` the only route under `/admin` is the gallery. Second, with the environment swapped to `production` and routes reloaded, there is none (FR-051).
- **Rationale**: absence is stronger than a guard. Nothing exists to misconfigure.
- **Alternatives**: a gate or middleware on an always-registered route (it would exist in production).

## R-14 Shell inputs for the future sign-in

- **Decision**: the layout `<x-admin::layouts.shell>` takes `operator` (a small DTO: display name and initial) and `signOutUrl`, both nullable. When they are absent the operator block renders only the language switcher. The gallery passes sample values. The sibling sign-in spec binds real values through a view composer, with no shell edit (FR-051).

## R-15 Blaze

- **Decision**: `Blaze::optimize()->in(app_path('Modules/Admin/Http/Views/components'))` with compile only (no memo, no fold). Blaze memoization or folding could freeze output that depends on the locale or the request. A primitive that misbehaves under compilation is marked `@unblaze` and noted in its contract entry. `BLAZE_ENABLED=false` is available to rule Blaze in or out while debugging.
- **Alternatives**: no Blaze (the dependency is already installed and approved; compile-only is the low-risk tier).
- **Outcome at implementation (2026-09-25)**: compile-only broke 90 of 139 primitive tests. Blaze 1.0.18 does not populate `@props` entries that have no default (`Undefined variable $minor`), and the catalogue relies on required props throughout. Blaze stays off; the full gallery renders well inside budget without it (T065). Follow-up: re-evaluate when Blaze supports required props, or give every prop a default and assert presence explicitly.

## R-16 Testing strategy

- **Unit** (no framework): `MoneyDisplayTest`, `TemporalDisplayTest` (Carbon is a plain library), `AuditReasonTest`.
- **Feature** (framework, PostgreSQL per suite config, though these tests touch no table):
  - one file per primitive family, rendering components with `$this->blade(...)` and asserting ARIA roles, the state classes present, and `dir` isolation;
  - `Livewire::test` for `Gallery`, `LocaleSwitcher` and the `RequiresReason` fixture;
  - `NavigationRegistryTest`, `ShellTest`, `DirectionTest`, `GalleryTest`, `GalleryAccessTest`, `AdminRouteSurfaceTest`, `TranslationCompletenessTest`.
- **Arch**: `AdminBackOfficeTest`. Livewire classes only under `Admin/Http/Livewire`. No Livewire public property typed as an Eloquent model. No Blade view for Admin outside `app/Modules/Admin/Http/Views`. No physical-direction utilities. No arbitrary colour or size values.
- **Manual**: visual fidelity against the design source, using the review checklist in [quickstart.md](./quickstart.md). Automated screenshot comparison would need a browser toolchain dependency, which was not approved and is not proposed.

## R-17 Asset entry points

- **Decision**: add `app/Modules/Admin/Http/Assets/admin.css` and `admin.js` to the Vite inputs, alongside the untouched `resources/css/app.css` (the public welcome page keeps Instrument Sans). `admin.css` declares `@source` for the Admin views directory. `admin.js` holds the small Alpine helpers: dialog opener memory, copy-to-clipboard, the toast store and the date-picker grid. The shell layout loads both with `@vite`.

## R-18 Content security

- **Decision**: no header change in this feature. Alpine's standard build evaluates expressions, which needs `'unsafe-eval'` under a strict CSP. Livewire 4 offers `csp_safe` for when a CSP is adopted. The security baseline currently sets HSTS only. This is recorded as a follow-up for whoever adds a CSP; it does not block D1.
