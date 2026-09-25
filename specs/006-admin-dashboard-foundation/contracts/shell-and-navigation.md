# Contract: Shell and navigation

## Layout — `<x-admin::layouts.shell>`

The frame every back-office page renders in (FR-006, board 06).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string | — | Used for the `<title>` and the page header, unless the `header` slot replaces it |
| `subtitle` | ?string | null | Page header |
| `breadcrumbs` | list of (label, ?href) | `[]` | The last item is current |
| `operator` | ?`OperatorPresence` | null | null means the operator block shows only the language switcher (R-14) |
| `sign-out-url` | ?string | null | null hides the sign-out button |
| `navigation` | ?list of groups with entries | null | null reads `NavigationRegistry`. The gallery passes samples |

| Slot | Purpose |
|---|---|
| default | Page content, max width 1200 px, 28/32 px padding |
| `actions` | Page-header action area, end-aligned |
| `status` | A pill beside the title |
| `header` | Replaces the whole page header |
| `search` | Top-bar search slot (the default is empty) |
| `notifications` | Top-bar notifications slot (the default is empty) |

**Rendered structure**: `<html lang dir data-theme="light">` → skip link → `<nav aria-label=admin::shell.primary>` sidebar → top bar (breadcrumbs, search, environment badge, notifications) → `<main id="content">` → toast stack. `lang` and `dir` come from the active locale (`config('admin.rtl_locales')`).

**Environment badge** (FR-009): `app()->environment()` maps to `admin::shell.environment.{local|staging|production}`. Production uses the `over` tone and the others `neutral`. Any other environment name shows raw in the neutral tone.

**Empty registry** (FR-010): each group with no entries is omitted. With no groups left, the sidebar shows `admin::shell.navigation_empty`.

## NavigationRegistry — `App\Modules\Admin\Http\Navigation\NavigationRegistry`

```text
group(string $key, string $labelKey, int $order): void
add(NavigationEntry $entry): void            // throws DuplicateNavigationEntry, UnknownNavigationGroup, UnknownIcon
groups(): list<ResolvedGroup>                // ordered; entries ordered; current resolved; badges evaluated
```

Features register in their own service provider's `boot()`:

```text
$registry->add(new NavigationEntry(
    key: 'claims', group: 'operate', labelKey: 'billing::admin.nav.claims',
    icon: 'claims', route: 'admin.claims', order: 20,
    badge: fn () => $claims->pendingCount(),
));
```

- **Current entry**: the request route name equals `route`, or starts with `route.`. At most one entry is current; if several match, the longest route wins.
- **Badges**: `badge` is called once per render; `null` or `0` hides it. A throwing badge closure is caught, reported, and hidden, so the shell never fails because of a count.

## LocaleSwitcher — Livewire `admin::locale-switcher`

- Public state: `string $locale` only (a scalar, per P-18 rule 5).
- Action: `switchTo(string $locale)` validates against `config('admin.supported_locales')`, writes `session('admin.locale')`, and redirects to the current URL so `dir` flips everywhere at once.
- Rendered in the operator block: a segmented control showing "English" and "العربية", each label in its own language.

## ResolveAdminLocale middleware

Applied to the Admin route group and registered as Livewire persistent middleware. Order: `session('admin.locale')` ∈ supported, then the first `ar` or `en` in `Accept-Language`, then `en`. It sets `App::setLocale` and Carbon's locale for the request.

## Gallery — Livewire `admin::gallery`, route `/admin/_gallery` (local only)

- Full page in the shell, with sample navigation, a sample `OperatorPresence` and sample breadcrumbs.
- Sections in board order: Tokens, Actions, Forms, Data, Feedback, Navigation, Values, Reason prompt.
- Each primitive is rendered in every state listed in [components.md](./components.md).
- Sample text comes from the gallery's own `admin::gallery.*` translation keys. It is invented and labelled "Sample".
- The in-page language control is the same `LocaleSwitcher`.
- Registered only when `app()->environment('local')` (R-13).
