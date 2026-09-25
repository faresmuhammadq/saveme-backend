# Contract: Value output and right-to-left rules

## Money — `MoneyPresenter` (R-8)

The input is `int $minor` and `string $currency` (ISO 4217, upper case). There is no floating-point arithmetic anywhere. Digits are always U+0030–U+0039.

| Currency | Exponent | en | ar |
|---|---|---|---|
| EGP | 2 | `EGP 1,290.00` | `1,290 ج.م` (the fraction is shown only when non-zero: `1,290.50 ج.م`) |
| SAR | 2 | `SAR 1,290.00` | `1,290 ر.س` |
| AED | 2 | `AED 1,290.00` | `1,290 د.إ` |
| USD | 2 | `USD 1,290.00` | `1,290 US$` |
| any other | 2 | `XYZ 1,290.00` | `1,290 XYZ` |

- **Negative**: `−EGP 40.00` / `−40 ج.م` (U+2212). An amount owed is passed positive, with an "owed" label by the caller (the glossary convention).
- **Zero**: `EGP 0.00` / `0 ج.م`.
- **Compact** (aggregate tiles only): thousands, millions and billions with one decimal, truncated not rounded. en: `EGP 1.2K`, `EGP 38.2M`; ar: `1.2 ألف ج.م`, `38.2 مليون ج.م`, `1.2 مليار ج.م`. The full value goes in `title` and `aria-label`.
- **Output**: the markup is `<data value="{minor} {CUR}"><bdi dir="ltr">…</bdi></data>`. In Arabic the isolation wraps only the number, so the symbol follows the Arabic reading order.

## Calendar date, instant, relative time — `TemporalPresenter` (R-9)

| Kind | Input | en | ar |
|---|---|---|---|
| Calendar date | `2026-09-25` | `2026-09-25` | `2026-09-25` (`ltr`) |
| Instant | `2026-09-25T18:04:00Z`, tz `Africa/Cairo` | `25 Sep 2026, 21:04 EEST` | `25 سبتمبر 2026، 21:04 EEST` |
| Relative (< 7 d) | same | `4 minutes ago` | `منذ 4 دقائق` |

No output may contain U+0660–U+0669. Zone abbreviations stay Latin in both languages.

## Identifier — `IdentifierPresenter`

This covers UUIDv7 and other opaque IDs. Up to 16 characters are shown whole; longer values show the first 6, `…`, then the last 6 (`0192f4…f2b6d3`). The copy action copies the full value. Output is mono in `ltr` isolation.

## Right-to-left rules (FR-030 to FR-037)

1. `<html dir>` follows the locale; no primitive sets `dir` except for isolation.
2. Layout uses only the logical utilities `ms`/`me`/`ps`/`pe`/`start`/`end`/`text-start`/`text-end`/`border-s`/`border-e`/`rounded-s`/`rounded-e`. Physical ones fail `AdminBackOfficeTest`.
3. Mirroring icons are chevron-start/end, sign-out, trend-up/down, external and refresh. Rings, via `rtl:-scale-x-100`, also mirror. Everything else stays as drawn.
4. These are isolated left-to-right with `<bdi dir="ltr">`: emails, identifiers, references, codes, URLs, calendar dates, instants, the number part of money, one-time-code inputs, and keyboard shortcuts.
5. Charts: the bar order follows the document, so the most recent bar is at the end in both directions.
6. Arabic typography: no `letter-spacing` and no `text-transform`; headings use 700 instead of 800 and taller leading (see [tokens.md](./tokens.md)).
7. Numerals are Western in both languages, with no locale digit substitution anywhere.
8. Mixed strings built by callers (for example `CLM-2046 · omar@example.com`) are passed through `<x-admin::ltr>`, or through `dir="auto"` on the containing cell when the first strong character decides.
