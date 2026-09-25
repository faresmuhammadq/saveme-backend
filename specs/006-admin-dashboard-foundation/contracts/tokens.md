# Contract: Design tokens

Source: the Foundations board of the design source, version `1790356228-859b`. These are defined once, as Tailwind 4 `@theme` variables in `app/Modules/Admin/Http/Assets/admin.css` (R-4). Primitives use only the utilities these generate. A dark theme later redefines the same names under `[data-theme="dark"]`. It is not shipped (FR-005).

## Colour

| Token | Light value | Use |
|---|---|---|
| `--color-canvas` | `#EDF0F5` | Page background |
| `--color-surface` | `#FFFFFF` | Cards, sidebar, top bar, dialogs |
| `--color-sunken` | `#F4F6FA` | Table header, input add-ons, quiet panels |
| `--color-sunken-2` | `#E9EDF3` | Neutral pill, disabled fill, skeleton |
| `--color-line` | `#E3E7EE` | Hairlines and card borders (decorative only) |
| `--color-field` | `#8A93A2` | Input, checkbox and secondary-button boundary (3:1 on surface) |
| `--color-ink` | `#161A22` | Primary text |
| `--color-ink-2` | `#5B6472` | Secondary text |
| `--color-ink-3` | `#6F7886` | Captions and placeholders (4.5:1 on surface; darker than Concept A's `#98A1B0`) |
| `--color-accent` | `#4C6FD6` | Primary action, selection, links, focus |
| `--color-accent-hover` | `#3F5FC2` | Primary hover |
| `--color-accent-pressed` | `#3553B0` | Primary pressed |
| `--color-accent-soft` | `#E9EEFC` | Selected row, active nav, info alert |
| `--color-on-accent` | `#FFFFFF` | Text on accent and danger fills |
| `--color-positive` | `#3F9E78` | Positive dots, bars and fills |
| `--color-positive-text` | `#2C7A5B` | Positive text |
| `--color-positive-soft` | `#E6F3EC` | Positive pill and alert |
| `--color-pending` | `#C98A2B` | Pending dots |
| `--color-pending-text` | `#8F5E12` | Pending text |
| `--color-pending-soft` | `#FBF1DE` | Pending pill and alert |
| `--color-over` | `#C56A4F` | Over and error dots |
| `--color-over-text` | `#A9503A` | Over/error text, danger-button fill |
| `--color-over-soft` | `#F7E9E3` | Over/error pill and alert |
| `--color-scrim` | `rgb(22 26 34 / .38)` | Dialog backdrop |
| `--color-ring` | `rgb(76 111 214 / .35)` | Focus ring |

## Type

The family is `'Hanken Grotesk', 'IBM Plex Sans Arabic', system-ui, sans-serif`, with tabular numerals (`font-feature-settings: 'tnum'`) on the shell root. Monospace is `ui-monospace, SFMono-Regular, Menlo, monospace`.

| Token | Size / weight / leading | Use |
|---|---|---|
| `numeral-xl` | 40 / 800 / 1.05 | Hero figures |
| `numeral-l` | 28 / 800 / 1.1 | Stat tiles |
| `h1` | 28 / 800 / 1.2 (Arabic: 700 / 1.35) | Page title |
| `h2` | 20 / 700 | Card title (large) |
| `h3` | 16 / 700 | Card title |
| `body` | 14 / 500 / 1.5 (Arabic: 1.6) | Default |
| `label` | 13 / 600 | Field label, table cell secondary |
| `caption` | 12 / 600 | Hints, meta |
| `overline` | 11 / 700, +0.14em, uppercase (Arabic: 12 / 700, no tracking, no case) | Section eyebrows |
| `mono` | 12.5 / 500 | Identifiers, references, codes |

Letter-spacing on headings (-0.02em) and overlines is removed under `:lang(ar)` (FR-035).

## Spacing, radius, elevation

- **Spacing**: a 4 px base with steps 4, 8, 12, 16, 20, 24, 32, 40, 56 and 64 (Tailwind's default scale). Arbitrary values are forbidden.
- **Radius**: `badge` 6, `control` 10, `tile` 14, `card` 18, `hero` 22, `pill` 9999.
- **Elevation**:
  - `flat`: 1 px `line` border and no shadow;
  - `raised`: `0 1px 2px rgb(20 30 55/.05), 0 1px 3px rgb(20 30 55/.06)`;
  - `overlay`: `0 12px 32px rgb(20 30 55/.14)`.
- **Focus ring**: 1 px `accent` border plus a 4 px `ring` spread, or on filled controls a 2 px `surface` gap plus a 3 px `ring`.

## Density

- Controls are 32 (sm), 40 (md) and 48 (lg) px tall.
- Table rows are 52 px, 60 px when a cell has two lines.
- Sidebar items are 38 px (English) and 40 px (Arabic, for taller glyphs).
- The shell sidebar is 248 px wide, the top bar 60 px tall, and page padding 28/32 px.
