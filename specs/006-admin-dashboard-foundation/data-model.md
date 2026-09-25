# Data Model: Admin Dashboard Foundation

**Nothing is persisted.** There are no migrations, tables, factories or seeders. The types below are in-memory: value objects and configuration that the primitives and shell depend on. All are owned by the Admin module.

## AuditReason — `App\Modules\Admin\Domain\AuditReason`

The reason an operator gives for an audited action.

| Field | Type | Rule |
|---|---|---|
| `value` | string | Trimmed. Non-blank after trimming. At most 500 characters, counted by `mb_strlen` so Arabic counts per character. |

- Construction fails with `InvalidAuditReason` (`blank` or `too_long`) when a rule is broken. There is no other way to obtain an instance.
- It is immutable and has no state transitions.
- It is used by the `RequiresReason` trait (see [contracts/reason-prompt.md](./contracts/reason-prompt.md)). Later features pass `value` to Identity's audit service as the `admin_action` reason.

## NavigationGroup — `App\Modules\Admin\Http\Navigation\NavigationGroup`

| Field | Type | Rule |
|---|---|---|
| `key` | string | Unique, kebab-case (`operate`, `configure`) |
| `labelKey` | string | A translation key under `admin::shell.groups.*`, present in `ar` and `en` |
| `order` | int | Ascending; ties are broken by `key` |

The shell ships two groups: `operate` (order 10) and `configure` (order 20). A feature may declare more through `NavigationRegistry::group()`.

## NavigationEntry — `App\Modules\Admin\Http\Navigation\NavigationEntry`

| Field | Type | Rule |
|---|---|---|
| `key` | string | Unique across the registry. A duplicate throws `DuplicateNavigationEntry` when registered at boot |
| `group` | string | Must name a declared group, otherwise `UnknownNavigationGroup` |
| `labelKey` | string | A translation key present in `ar` and `en` |
| `icon` | string | A name in `IconRegistry`, otherwise `UnknownIcon` |
| `route` | string | A route name. It is current when the request's route name equals it or starts with it followed by `.` |
| `order` | int | Ascending within the group; ties are broken by `key` |
| `badge` | `?Closure(): ?int` | Called at render. `null` or `0` hides the badge. Must be cheap: later features call a query service |

There is deliberately no visibility or role field, because every operator is an admin in MVP (spec, Assumptions).

## OperatorPresence — `App\Modules\Admin\Http\Shell\OperatorPresence`

The shell's input for the operator block (FR-051, R-14).

| Field | Type | Rule |
|---|---|---|
| `displayName` | string | Shown in the sidebar footer |
| `initial` | string | One grapheme, for the avatar |
| `statusKey` | string | A translation key, e.g. `admin::shell.operator.two_factor_on` |

The shell takes `?OperatorPresence $operator` and `?string $signOutUrl`. Both are null until the sibling sign-in spec binds them, and the gallery passes a sample.

## Session state

| Key | Values | Set by | Read by |
|---|---|---|---|
| `admin.locale` | `ar` \| `en` | `LocaleSwitcher` | `ResolveAdminLocale` (fallback order: session, then `Accept-Language`, then `en`) |

## Configuration — `config/admin.php`

| Key | Default | Purpose |
|---|---|---|
| `timezone` | `Africa/Cairo` | The operator timezone for instant display until operator accounts carry one |
| `supported_locales` | `['en', 'ar']` | The switcher options and middleware whitelist |
| `rtl_locales` | `['ar']` | Which locales render `dir="rtl"` |

## Icon registry — `App\Modules\Admin\Http\Icons\IconRegistry`

A map from name to `{paths: string, mirrors: bool}`, drawn from the design source's icon board: overview, users, claims, plans, audit, wallet, search, filter, bell, lock, check, close, add, more, copy, date, instant, trend-up, trend-down, private, export, chevron-start, chevron-end, chevron-up, chevron-down, sign-out, info, warning, error, success, image, key, device, refresh, trash, external. `mirrors` is true for chevron-start, chevron-end, sign-out, trend-up, trend-down, external and refresh.
