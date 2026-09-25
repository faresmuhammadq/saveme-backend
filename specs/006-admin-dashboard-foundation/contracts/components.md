# Contract: Primitives

Every primitive is an anonymous Blade component, `<x-admin::name>`, and is **stateless**: it renders props and slots, and any interaction it emits goes to a `wire:*` or Alpine target passed in by the caller (R-2).

These rules apply to every entry:

- **Visual source**: the named board of the design source, version `1790356228-859b`.
- **Text**: all built-in text comes from the `admin::` translation files (FR-036). Callers pass already-translated text for their own labels.
- **Direction**: logical utilities only (R-5). The RTL column lists what changes beyond automatic mirroring.
- **Attributes**: extra attributes (`wire:click`, `x-on:*`, `id`, `data-*`) pass through `$attributes` to the primary interactive element.
- **Focus**: every interactive element shows the focus ring from [tokens.md](./tokens.md) under `:focus-visible` (FR-019).

A state named here must be reachable through a prop, so the gallery can render it statically. Hover and pressed are shown in the gallery by a `preview-state` prop that is ignored in production rendering.

## Actions — board 02

| Component | Props | Slots | States | Accessibility | RTL |
|---|---|---|---|---|---|
| `button` | `variant` primary\|secondary\|danger\|ghost (default primary); `size` sm\|md\|lg (md); `icon`, `icon-end`; `type` (button); `loading` bool; `disabled` bool; `href` (renders `<a>`) | default (label) | default, hover, focus, pressed, loading (spinner, `aria-busy`, click ignored), disabled | Real `<button>` or `<a>`. Loading keeps its width | `icon` sits at the start, `icon-end` at the end; mirroring icons flip |
| `link` | `href`; `variant` default\|quiet; `external` bool | default | default, hover (underline), focus | External adds a visually hidden "opens in new tab" and `rel="noopener"` | The external icon mirrors |
| `icon-button` | `icon`; `label` (required); `variant` ghost\|outline\|filled\|danger; `size` | — | default, hover, focus, disabled | `aria-label` = `label`. Rendering without `label` throws | — |
| `segmented` | `options` (list of value/label); `value`; `name` | — | selected, unselected, focus | `role="radiogroup"` with radio semantics | Order follows the document |
| `split-button` | `variant`; `menu-label` | default (primary action), `menu` (items) | as button, plus menu open | The toggle has `aria-haspopup="menu"` and `aria-expanded` | The divider sits at the end of the primary part |
| `menu` | `label`; `align` start\|end | `trigger`, default (items) | closed, open, item highlighted | APG menu button: arrows, Home/End, Enter, Escape; typeahead | Anchored at start or end |
| `menu.item` | `icon`; `shortcut`; `tone` default\|danger; `disabled`; `disabled-reason`; `opens-prompt` bool (renders the trailing "…") | default | default, highlighted, disabled with reason | `role="menuitem"`, with `aria-disabled` and the reason in `aria-describedby` | The shortcut moves to the end |
| `menu.separator`, `menu.heading` | — | default | — | `role="separator"`; the heading is `role="presentation"` | — |

Menu rules: destructive items sit last, after a separator. A destructive item is never focused first on open (the menu enforces this by order).

## Forms — board 03

| Component | Props | Slots | States | Accessibility | RTL |
|---|---|---|---|---|---|
| `field` | `label`; `for`; `required`; `hint`; `error` | default (the control) | default, error | Label `for`, hint and error joined in `aria-describedby`; error has `role` none, and the summary announces it | — |
| `input` | `type` text\|email\|password; `name`; `value`; `placeholder`; `invalid`; `disabled`; `readonly`; `dir` (auto for email and URL values = ltr) | `prefix`, `suffix` | default, focus, filled, invalid, disabled, readonly | Pairs with `field` | Email, URL and code inputs force `dir="ltr"` |
| `number` | as `input`, plus `min`, `max`, `step` | — | as `input` | `inputmode="numeric"` | Always `dir="ltr"`, aligned to the end |
| `money-input` | `currency`; `name` (the minor-units value); `value` (int minor units) | — | as `input` | Shows the major-unit text; submits minor units; hint shows `admin::values.stored_as` | Always `dir="ltr"`; the currency add-on sits at the start |
| `textarea` | `name`; `rows`; `max` (shows a counter); `invalid`; `disabled` | — | as `input`, plus counter near or over the limit | The counter is in `aria-describedby`, polite | — |
| `select` | `name`; `options` (value, label, meta); `value`; `placeholder`; `invalid`; `disabled` | — | closed, open, option highlighted, selected, disabled | APG select-only combobox with a hidden native input carrying the value | Meta aligns to the end |
| `checkbox` | `name`; `checked`; `indeterminate`; `disabled`; `label` | — | unchecked, checked, indeterminate, disabled, focus | Native input, custom-drawn; `aria-checked="mixed"` when indeterminate | — |
| `radio-group` | `name`; `options`; `value`; `style` card\|plain | — | selected, unselected, focus, disabled | `fieldset` + `legend` | — |
| `toggle` | `name`; `checked`; `disabled`; `label`; `description` | — | on, off, disabled, focus | `role="switch"` with `aria-checked` | The knob travels toward the end when on |
| `date-picker` | `name`; `value` (`Y-m-d`); `min`, `max` | — | closed, open, day focused, selected, out-of-month, disabled day | APG dialog date picker: arrow keys, PageUp/PageDown for months, Enter, Escape. Digits always Western | Grid order mirrors; the value text stays `ltr` |
| `instant-picker` | `name`; `value` (UTC ISO-8601); `timezone` (default config) | — | as date-picker, plus time field | Shows the zone name and the stored UTC string (`admin::values.stored_utc`) | Value text stays `ltr` |
| `search` | `name`; `placeholder`; `shortcut` (default `/`) | — | default, focus, with value (shows clear) | `role="search"` wrapper; the shortcut is announced in the label | The icon sits at the start |
| `form-section` | `title`; `description` | default (fields), `footer` | — | `<section>` with `aria-labelledby` | The description column sits at the start |
| `validation-summary` | `errors` (field id → message) | — | hidden when empty | `role="alert"`; each entry links `#field-id` and focuses it | — |

## Data — board 04

| Component | Props | Slots | States | Accessibility | RTL |
|---|---|---|---|---|---|
| `table` | `columns` (key, label, align start\|end, sortable, width); `sort` (key, dir); `selectable`; `selected` (ids); `loading`; `empty` (title, body) | `toolbar`, `row` (per record), `footer` | populated, hover row, selected row, loading (skeleton rows, never empty first), empty | Real `<table>`; the sort header is a button with `aria-sort`; the selection header checkbox is indeterminate when partial | The numeric/end-aligned columns follow `align` |
| `table.filters` | `applied` (key, label); `available` | — | none, applied (removable chips), add filter | Chip remove buttons are labelled `admin::data.remove_filter` | — |
| `table.pagination` (cursor) | `previous` (url or null); `next` (url or null); `summary` | — | first page (newer disabled), middle, last | `<nav aria-label>` | "Newer" points toward the start, "Older" toward the end |
| `description-list` | `items` (term, value) or a slot | default | — | `<dl>` | Terms at the start |
| `stat-tile` | `label`; `value` (preformatted by a value primitive); `delta` (text, meaning good\|bad\|neutral, direction up\|down); `caption`; `ring` (0–100); `bars` (list of int); `loading`; `suppressed` | `badge` | default, with delta, with sparkline, hero ring, loading, suppressed ("Fewer than 10", with the private icon) | The delta is spoken with its meaning ("21% more, unfavourable"). The ring value is in the text | Rings and trend arrows mirror; sparklines read start→end |
| `card` | `title`; `subtitle`; `padding` default\|none; `elevation` flat\|raised | default, `actions`, `footer` | — | `<section>` when titled | — |

The delta colour follows `meaning`, never `direction` (FR-013).

## Feedback and overlays — board 05

| Component | Props | Slots | States | Accessibility | RTL |
|---|---|---|---|---|---|
| `badge` | `tone` accent\|neutral\|outline\|count\|danger-count\|dot; `icon` | default | — | The dot has a visually hidden label | — |
| `pill` | `tone` positive\|pending\|over\|info\|neutral | default | — | The text carries the meaning; the dot is decorative | — |
| `alert` | `tone` info\|success\|warning\|error; `title`; `dismissible` | default (body), `action` | — | `role="status"` for info and success, `role="alert"` for warning and error | The icon sits at the start |
| `toast-stack` | — (one per layout) | — | 0–3 visible, stacked at the bottom end | `aria-live="polite"`, or assertive for errors. Errors never auto-dismiss; the others dismiss after 6 s, paused on hover or focus | Stacks in the bottom-end corner |
| toast event | `$this->dispatch('admin-toast', tone:, message:, undo: ?string event name, handled with #[On])` | — | success, info, error; with undo | Undo is a button | — |
| `empty-state` | `icon`; `title`; `tone` neutral\|positive\|search | default (body), `action` | — | Heading level via `level` prop (default 2) | — |
| `skeleton` | `lines`; `shape` line\|circle\|tile | — | animating; reduced motion disables animation | `aria-hidden`, with the owning region `aria-busy` | — |
| `dialog` | `name` (id); `title`; `description`; `size` sm\|md\|lg; `dismissible` | default, `footer` | closed, open | Native `<dialog>`, `showModal`, `x-trap`, Escape closes, focus returns to the opener (R-10); `aria-labelledby` and `aria-describedby` | Close button at the end |
| `confirm-dialog` | as dialog, plus `tone` danger\|default; `confirm-label`; `confirm-phrase` (typed confirmation); `confirm` (wire method) | default | idle, phrase incomplete (confirm disabled), ready | `role="alertdialog"`; the initial focus is the cancel button | — |
| `modal` | alias of `dialog` sized md | — | — | — | — |
| `slide-over` | as dialog; `width` | default, `footer` | closed, open | As dialog | Slides in from the end edge |
| `reason-prompt` | see [reason-prompt.md](./reason-prompt.md) | — | — | — | — |

## Navigation — board 06

| Component | Props | Slots | States | Accessibility | RTL |
|---|---|---|---|---|---|
| `tabs` | `style` underline\|pill; `items` (key, label, count, href or wire target); `current` | — | current, other, focus | `role="tablist"` when switching in-page; `<nav>` links when routing | Order follows the document |
| `breadcrumbs` | `items` (label, href); the last is current | — | — | `<nav aria-label>`, `aria-current="page"` on the last | The separator chevron mirrors |
| `pagination` (numbered) | `current`; `last`; `url` pattern | — | first, middle, last, ellipsis | `aria-current="page"` | Previous/next chevrons mirror |

## Value display — board 06

The output rules are in [values-and-rtl.md](./values-and-rtl.md).

| Component | Props | Output |
|---|---|---|
| `money` | `minor` (int, required); `currency` (ISO 4217, required); `compact` bool; `sign` auto\|always | `<data value="{minor}">` in `ltr` isolation. Compact adds the full value in `title` and `aria-label` |
| `date` | `value` (`Y-m-d`) | `<time datetime>`; never timezone-shifted |
| `instant` | `value` (UTC ISO-8601); `timezone` | `<time datetime>` with the zone abbreviation |
| `relative-time` | `value` (UTC); `threshold-days` (7) | Relative under the threshold, otherwise falls back to `instant`; the absolute value in `title` and `aria-label` |
| `identifier` | `value`; `label` (for copy); `truncate` (default 6+6) | Mono, `ltr`, middle ellipsis; the copy button confirms "Copied" for 2 s |
| `icon` | `name`; `size` 14\|16\|18\|20\|24; `label` (null = decorative) | Inline SVG from `IconRegistry`; `aria-hidden` unless labelled; `rtl:-scale-x-100` when `mirrors` |
| `ltr` | — | `<bdi dir="ltr">` around the slot |

## Shell — see [shell-and-navigation.md](./shell-and-navigation.md)
