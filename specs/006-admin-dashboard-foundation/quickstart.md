# Quickstart: Admin Dashboard Foundation

How to prove the feature works once it's built. This is a validation guide, not an implementation guide.

## Prerequisites

- The environment is running: `dev project open` (app at http://saveme-backend.test).
- `APP_ENV=local` in `.env`.
- Assets are built: `dev exec -- npm run build`, or `dev exec -- npm run dev` while reviewing. The first build downloads Hanken Grotesk and IBM Plex Sans Arabic and self-hosts them (R-6).

## 1. Automated gates

```bash
dev exec -- vendor/bin/pest tests/Unit/Admin tests/Feature/Admin tests/Arch/AdminBackOfficeTest.php --compact
dev exec -- composer test        # full gate: pint, larastan level 8, arch, suite
```

Expected: all pass. To show the gates bite, make each of these edits temporarily, confirm the named test fails, then revert:

| Seeded violation | Must fail |
|---|---|
| Add `class="ml-2"` to any Admin view | `AdminBackOfficeTest` (physical-direction lint) |
| Add `class="bg-[#ff0000]"` to any Admin view | `AdminBackOfficeTest` (token lint) |
| Put a Livewire class in `app/Livewire/` | `AdminBackOfficeTest` (P-18 rule 3) |
| Type a Livewire public property as an Eloquent model | `AdminBackOfficeTest` (P-18 rule 5) |
| Delete one key from `Lang/ar/forms.php` | `TranslationCompletenessTest` |
| Make `MoneyPresenter` call `number_format` | `MoneyDisplayTest` (the large-value precision case) |
| Register a second route under `/admin` | `AdminRouteSurfaceTest` |
| Make an audited fixture method public | `ReasonPromptTest` |

## 2. Gallery access

- Open http://saveme-backend.test/admin/_gallery. The gallery renders inside the shell with sample navigation.
- Set `APP_ENV=production` and run `dev artisan optimize:clear`. The same URL returns 404. Restore `local` afterwards.

## 3. Language and direction

1. In the operator block, switch to **العربية**. The page reloads with `dir="rtl"`: sidebar on the right, breadcrumb chevrons pointing left, the slide-over entering from the left.
2. Check that identifiers, emails, references, dates and instants read left to right inside Arabic text, and that amounts read `1,290 ج.م` with Western digits.
3. Reload and open another tab. Arabic persists for the session.
4. In a fresh private window with the browser language set to Arabic, the first visit opens in Arabic. With the browser set to French, it opens in English.

## 4. Visual fidelity review (SC-001 to SC-005)

Open the design source (https://claude.ai/artifact/Ag9pG4RnifekirczEG2drM, version `1790356228-859b`) beside the gallery. For each board, tick every item or record the difference:

- [ ] **Foundations**: every token swatch matches [contracts/tokens.md](./contracts/tokens.md) exactly; the type scale, radii and elevation match.
- [ ] **Actions**: 4 variants × 6 states, 3 sizes, icons, segmented, split, icon buttons, links, the open menu with a disabled reason and destructive last.
- [ ] **Forms**: field states, money input hint, textarea counter, select open, checkbox (4 states), toggle (3 states), radio cards, date picker grid (Western digits), instant picker with the zone and UTC line, form section, validation summary links.
- [ ] **Data**: the stat tile set including suppressed and loading, a table with filters, bulk selection, hover and selected rows, the empty table, the loading table, cursor pagination, description list.
- [ ] **Feedback**: pills (subscription and claim sets), badges, 4 alerts, the toast stack, confirm dialog with a typed phrase, slide-over, empty state, skeleton, reason prompt.
- [ ] **Navigation and values**: shell, tabs (both styles), breadcrumbs, both paginations, and every value display.
- [ ] **Arabic**: repeat every board. There are 0 mirroring defects against the rules in [contracts/values-and-rtl.md](./contracts/values-and-rtl.md), checked against the design's Arabic screen boards.
- [ ] **Keyboard only**: tab through the whole gallery. Every interactive part is reachable with a visible ring. Dialogs trap focus, close on Escape, and return focus to the opener. Menus and the listbox work with arrow keys.
- [ ] **Contrast**: spot-check caption text, pills and field borders with a contrast checker (4.5:1 for text, 3:1 for boundaries).

Record the result under the plan's review section in the pull request. If the design source is missing a state, note the nearest drawn state used (spec, Assumptions).

## 5. Composability smoke check (SC-006)

`NavigationRegistryTest` registers a fixture group and entry in a test-only provider and renders a page under it. It asserts the entry appears with its badge and `aria-current`. The registration goes through `NavigationRegistry::add` only, so no shell file needs editing, and no manual step is needed.
