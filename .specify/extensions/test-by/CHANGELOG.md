# Changelog

All notable changes to the SpecKit Test-By extension are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.2.0] — 2026-09-23

### Changed

- Extension ID renamed from `test` to `test-by`; extension name updated to "Test-By"
- Commands renamed: `speckit.test.validate` → `speckit.test-by.validate`, `speckit.test.e2e` → `speckit.test-by.e2e`
- Slash commands updated accordingly: `/speckit-test-validate` → `/speckit-test-by-validate`, `/speckit-test-e2e` → `/speckit-test-by-e2e`
- Command files moved from `commands/speckit.test.validate.md` / `commands/speckit.test.e2e.md` to `commands/validate.md` / `commands/e2e.md`

---

## [1.1.0] — 2026-09-23

### Added

- `speckit.test.e2e` command — execute test cases defined in a CSV file via Playwright MCP
- CSV format with required columns (`test_case_id`, `test_case_name`, `step`, `action`) and optional columns (`target`, `value`, `notes`)
- Column name aliases for flexible CSV authoring (e.g. `id`, `name`, `#`, `element`, `input`)
- Full action vocabulary: `navigate`, `click`, `fill`, `type`, `select`, `hover`, `press`, `assert_text`, `assert_url`, `assert_element`, `screenshot`, `wait`, `scroll`, `evaluate`
- Assertion steps (`assert_*`) continue remaining steps on failure rather than stopping the test case
- Step-level detail table in failure sections (action / target / value / status / error)
- Fall-back to `<feature_directory>/test-cases/e2e.csv` when no CSV path is provided
- `report_filename_prefix: e2e` default for CSV-driven reports
- Claude Code skill at `.claude/skills/speckit-test-e2e/SKILL.md`
- GitHub Copilot prompt at `.github/prompts/speckit.test.e2e.prompt.md`
- Hook registration points: `before_test_e2e` / `after_test_e2e`
- Extension version bumped to `1.1.0`

---

## [1.0.0] — 2026-09-23

### Added

- `speckit.test.validate` command — executes acceptance scenarios from `spec.md` via Playwright MCP
- Inline Given/When/Then parsing for scenarios in `**Acceptance Scenarios**:` blocks
- Automatic browser action mapping: click, fill, type, select, hover, press key, navigate
- Then-assertion verification using `browser_get_visible_text` and `browser_snapshot`
- PNG screenshot capture on step and assertion failures
- Structured markdown report written to `<feature-dir>/test-reports/validation-<timestamp>.md`
- Observational notes: console errors, slow loads, unexpected redirects
- Graceful degradation for missing Playwright MCP, missing spec, missing URL
- `config-template.yml` configuration: timeouts, retry count, base URL, screenshot settings, browser viewport
- Claude Code skill at `.claude/skills/speckit-test-validate/SKILL.md`
- GitHub Copilot prompt at `.github/prompts/speckit.test.validate.prompt.md`
- Base URL override via command argument: `/speckit-test-validate https://staging.example.com`
- Optional hook registration support (`before_test_validate` / `after_test_validate`)
