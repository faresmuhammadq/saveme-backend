---
description: "Load test cases from a CSV file and execute each step via Playwright browser automation, producing a structured validation report"
---

# E2E Test Execution from CSV

Load test cases from a CSV file, execute every step through the Playwright MCP browser automation tools, and write a structured markdown report — using the same format as `speckit.test.validate` — with pass/fail per test case, observational notes, and PNG screenshots on failure.

## User Input

```text
$ARGUMENTS
```

`$ARGUMENTS` must contain the path to the CSV file (absolute or relative to the project root). Example:

```
__SPECKIT_COMMAND_TEST_E2E__ tests/login-flow.csv
```

If `$ARGUMENTS` is empty, look for a CSV file in the active feature directory at `<feature_directory>/test-cases/e2e.csv`. If that also does not exist, print an error and stop:

> **Error**: No CSV file specified. Usage: `__SPECKIT_COMMAND_TEST_E2E__ <path-to-csv>` or place a file at `<feature_directory>/test-cases/e2e.csv`.

## CSV Format

The CSV file must have a header row. Supported column names (case-insensitive, leading/trailing whitespace stripped):

| Column | Required | Description |
|--------|----------|-------------|
| `test_case_id` | Yes | Identifier that groups steps into one test case (e.g. `TC-001`) |
| `test_case_name` | Yes | Human-readable name shown in the report |
| `step` | Yes | Step number within the test case (integer, 1-based) |
| `action` | Yes | The browser action to perform (see Action Reference below) |
| `target` | No | Element label, CSS selector, URL, or key name depending on action |
| `value` | No | Value to type, select, or assert depending on action |
| `notes` | No | Optional freetext annotation shown in the report |

**Column name aliases** (all treated identically):
- `test_case_id` = `id` = `tc_id` = `case_id`
- `test_case_name` = `name` = `title` = `test_name`
- `step` = `step_number` = `step_no` = `#`
- `action` = `type` = `action_type`
- `target` = `element` = `selector` = `url`
- `value` = `input` = `data` = `expected`
- `notes` = `note` = `comment` = `description`

**Example CSV:**

```csv
test_case_id,test_case_name,step,action,target,value,notes
TC-001,User Login Success,1,navigate,https://example.com/login,,
TC-001,User Login Success,2,fill,Email,admin@example.com,
TC-001,User Login Success,3,fill,Password,secret,
TC-001,User Login Success,4,click,Sign In button,,
TC-001,User Login Success,5,assert_text,,Welcome Admin,Check dashboard greeting
TC-002,Failed Login,1,navigate,https://example.com/login,,
TC-002,Failed Login,2,fill,Email,bad@user.com,
TC-002,Failed Login,3,fill,Password,wrongpass,
TC-002,Failed Login,4,click,Sign In,,
TC-002,Failed Login,5,assert_text,,Invalid credentials,
TC-003,Password Reset Flow,1,navigate,https://example.com/login,,
TC-003,Password Reset Flow,2,click,Forgot password,,
TC-003,Password Reset Flow,3,assert_url,https://example.com/reset,,Verify redirect
TC-003,Password Reset Flow,4,fill,Email,user@example.com,
TC-003,Password Reset Flow,5,click,Send reset email,,
TC-003,Password Reset Flow,6,assert_text,,Check your inbox,
```

### Action Reference

| `action` value | Playwright tool | `target` | `value` |
|----------------|----------------|----------|---------|
| `navigate` | `mcp__playwright__browser_navigate` | URL | — |
| `click` | `mcp__playwright__browser_click` | Element label/selector | — |
| `fill` | `mcp__playwright__browser_fill` | Element label/selector | Text to enter |
| `type` | `mcp__playwright__browser_type` | Element label/selector | Text to type |
| `select` | `mcp__playwright__browser_select_option` | Element label/selector | Option label/value |
| `hover` | `mcp__playwright__browser_hover` | Element label/selector | — |
| `press` | `mcp__playwright__browser_press_key` | Key name (e.g. `Enter`, `Tab`) | — |
| `assert_text` | `mcp__playwright__browser_get_visible_text` | — | Expected text (substring match) |
| `assert_url` | `mcp__playwright__browser_snapshot` | Expected URL (substring match) | — |
| `assert_element` | `mcp__playwright__browser_snapshot` | Element label/selector | — |
| `screenshot` | `mcp__playwright__browser_screenshot` | — | Optional filename stem |
| `wait` | pause | Milliseconds (in `target`) or seconds (e.g. `2s`) | — |
| `scroll` | `mcp__playwright__browser_evaluate` | `up` / `down` / `top` / `bottom` | — |
| `evaluate` | `mcp__playwright__browser_evaluate` | JavaScript expression | Optional expected return value |

Unrecognised action values: log a warning in the report ("Unknown action: X — step skipped") and continue.

## Step 1 — Validate Input and Locate CSV

1. Parse `$ARGUMENTS` to extract the CSV path.
2. If no path given, resolve the default: read `.specify/feature.json` → get `feature_directory` → check `<feature_directory>/test-cases/e2e.csv`.
3. If the CSV file does not exist at the resolved path, print an error and stop.
4. Set `CSV_FILE` to the resolved path.
5. Set `CSV_DIR` to the directory containing the CSV file (used for resolving relative screenshot paths).

## Step 2 — Load Configuration

Read `.specify/extensions/test/config-template.yml` if it exists. Use these defaults if absent:

- `screenshot_on_failure: true`
- `screenshot_dir: test-reports/screenshots`
- `report_dir: test-reports`
- `report_filename_prefix: e2e`
- `timeout_ms: 10000`
- `retry_count: 1`

Determine `REPORT_ROOT`: if an active feature directory exists (`.specify/feature.json` is present and readable), use `<feature_directory>/` as the root for report and screenshot directories. Otherwise, use `CSV_DIR/` as the root.

## Step 3 — Parse the CSV

1. Read the CSV file as plain text.
2. Parse the header row (first line). Map column names to their canonical names using the alias table above. If `test_case_id`, `test_case_name`, `step`, or `action` columns are missing after alias resolution, print an error and stop:
   > **Error**: CSV is missing required columns: `<list>`. See the CSV format documentation for required headers.
3. Parse each data row into a step record: `{ test_case_id, test_case_name, step, action, target, value, notes }`. Trim whitespace from all values. Treat empty cells as empty strings.
4. Group rows by `test_case_id`, preserving order. Within each group, sort by `step` (ascending integer).
5. Build a list of **test cases**, each with:
   - `id`: `test_case_id`
   - `name`: `test_case_name` (from first row of group)
   - `slug`: kebab-case of `test_case_name` (for file naming)
   - `steps`: ordered list of step records

If the parsed list is empty, print a warning and stop:
> **Warning**: CSV file contains no data rows. Add test steps and retry.

Report the parsed count before execution:
```
Loaded <N> test case(s) with <M> total steps from <CSV_FILE>
```

## Step 4 — Prepare Output Directories

Resolve paths relative to `REPORT_ROOT`:
- Report directory: `<REPORT_ROOT>/<report_dir>/`
- Screenshot directory: `<REPORT_ROOT>/<screenshot_dir>/`

Create both directories.

Generate report filename: `<report_filename_prefix>-<YYYYMMDD-HHMMSS>.md`
Set `REPORT_FILE` to `<REPORT_ROOT>/<report_dir>/<report_filename>`.

## Step 5 — Execute Test Cases

For each test case, execute all steps in order. Keep a running results ledger.

### 5a. Execute Each Step

For each step record:

**`navigate`**:
Call `mcp__playwright__browser_navigate` with `url: target`. If `target` is not a full URL (no protocol), prepend `https://`. On failure: capture screenshot, mark test case FAIL at this step, stop further steps for this test case.

**`click`**:
Call `mcp__playwright__browser_click` with `element: target`. On failure: screenshot, FAIL, stop.

**`fill`**:
Call `mcp__playwright__browser_fill` with `element: target` and `value: value`. On failure: screenshot, FAIL, stop.

**`type`**:
Call `mcp__playwright__browser_type` with `element: target` and `text: value`. On failure: screenshot, FAIL, stop.

**`select`**:
Call `mcp__playwright__browser_select_option` with `element: target` and `value: value`. On failure: screenshot, FAIL, stop.

**`hover`**:
Call `mcp__playwright__browser_hover` with `element: target`. On failure: screenshot, FAIL, stop (non-blocking — if hover itself succeeds but causes no state change, that is acceptable).

**`press`**:
Call `mcp__playwright__browser_press_key` with `key: target`. On failure: screenshot, FAIL, stop.

**`assert_text`**:
Call `mcp__playwright__browser_get_visible_text`. Check whether `value` appears as a substring in the returned text (case-sensitive unless `value` has no uppercase letters, in which case use case-insensitive match). If not found:
- Also call `mcp__playwright__browser_snapshot` and check the accessibility tree.
- If still not found: screenshot, record the nearest matching text fragment (fuzzy search for words from `value`), mark FAIL for this step but **continue to the next step** (assertion failures do not stop remaining steps — they are logged and the test case is marked FAIL at the end).

**`assert_url`**:
Call `mcp__playwright__browser_snapshot` to read the current page URL. Check whether `target` appears as a substring of the current URL. On mismatch: screenshot, record actual URL, mark step FAIL (continue remaining steps).

**`assert_element`**:
Call `mcp__playwright__browser_snapshot`. Check whether `target` appears in the accessibility snapshot (by role, label, or text). On missing: screenshot, mark step FAIL (continue remaining steps).

**`screenshot`**:
Always capture a screenshot regardless of step outcome. Save as `<test-case-slug>-step<N>-manual.png` (or use `value` as the filename stem if provided). This is a manual screenshot step — never marks FAIL. Note the path in the report.

**`wait`**:
Pause for the specified duration. Parse `target` as milliseconds (integer) or seconds (`Ns` / `N.Ns`). Do not call any Playwright tool. Log "Waited Xms" in the report. Never marks FAIL.

**`scroll`**:
Call `mcp__playwright__browser_evaluate` with a JS expression:
- `up` → `window.scrollBy(0, -500)`
- `down` → `window.scrollBy(0, 500)`
- `top` → `window.scrollTo(0, 0)`
- `bottom` → `window.scrollTo(0, document.body.scrollHeight)`

**`evaluate`**:
Call `mcp__playwright__browser_evaluate` with `script: target`. If `value` is non-empty, compare the return value to `value`; on mismatch, mark step FAIL (continue remaining steps).

### 5b. Retry Logic

For steps that interact with elements (`click`, `fill`, `type`, `select`, `hover`, `press`), if the tool call fails and `retry_count > 0`, wait 1 second and retry once. Record in notes if a retry succeeded.

### 5c. Screenshot on Failure

When capturing a failure screenshot:
1. Call `mcp__playwright__browser_screenshot`.
2. Save the base64 PNG to `<REPORT_ROOT>/<screenshot_dir>/<test-case-slug>-step<N>-failure.png`.
3. Record the relative path (from the report file location) in the failure details.
4. If screenshot capture itself fails, note "screenshot unavailable" and continue.

### 5d. Test Case Outcome

After all steps are processed:
- **PASS**: all steps completed without any FAIL record.
- **FAIL**: at least one step has a FAIL record.
- **SKIPPED**: test case was skipped (e.g., marked skip in CSV — reserved for future use).

### 5e. Observational Notes

Regardless of outcome, note automatically:
- `⚠ Console error observed:` if the snapshot contains error-level entries
- `⚠ Slow response:` if any navigation took over 5 seconds
- `⚠ Unexpected URL:` if the page URL differs significantly from what navigate targeted (possible redirect)
- `⚠ Step notes:` text from the `notes` column for any step that has a non-empty value

## Step 6 — Write the Report

Before writing the report, collect token usage statistics for the session:
- `TOTAL_STEPS_EXECUTED`: count of all steps across all test cases that were actually run (not skipped)
- `PLAYWRIGHT_TOOL_CALLS`: count of every `mcp__playwright__browser_*` tool call made during execution
- `INPUT_TOKENS` / `OUTPUT_TOKENS`: report these from your current session usage if available; otherwise write "N/A"
- `TOTAL_TOKENS`: sum of input and output tokens if both are available; otherwise "N/A"
- `MODEL`: the model name used for this session (e.g. `claude-sonnet-4-6`)

After all test cases complete, write `REPORT_FILE` using exactly this structure:

```markdown
# SpecKit E2E Test Report

**Source**: <CSV_FILE>
**Date**: <YYYY-MM-DD HH:MM:SS>
**Overall Status**: ✅ PASSED (<N> passed, 0 failed) | ❌ FAILED (<N> passed, <M> failed) | ⚠ PARTIAL (<N> passed, <M> failed, <K> skipped)

---

## Results

| # | Test Case | Status | Notes |
|---|-----------|--------|-------|
| 1 | <test_case_name> [TC-001] | ✅ PASS / ❌ FAIL / ⏭ SKIPPED | <brief note or empty> |
...

---

## Failure Details

(one section per FAILED test case)

### <test_case_name> [<test_case_id>]

| Step | Action | Target | Value | Status | Error |
|------|--------|--------|-------|--------|-------|
| 1 | navigate | https://example.com | | ✅ | |
| 2 | fill | Email | admin@example.com | ✅ | |
| 3 | click | Sign In button | | ❌ | Element not found after 10000ms |

**Screenshot** (step 3):
![Failure screenshot](<relative/path/to/screenshot.png>)

**Observed page content** (nearest match):
> <quote from page>

---

## Observational Notes

(one section per test case that has notes, including passing ones)

### <test_case_name> [<test_case_id>]

- ⚠ <note text>
- Step 4 note: <notes column value>

---

## Token Usage

| Metric | Value |
|--------|-------|
| Model | <MODEL> |
| Input tokens | <INPUT_TOKENS> |
| Output tokens | <OUTPUT_TOKENS> |
| Total tokens | <TOTAL_TOKENS> |
| Playwright tool calls | <PLAYWRIGHT_TOOL_CALLS> |
| Steps executed | <TOTAL_STEPS_EXECUTED> |

---

## How to Re-run

```
__SPECKIT_COMMAND_TEST_E2E__ <CSV_FILE>
```
```

Write the file to `REPORT_FILE`.

Print a brief summary:
```
✅ E2E execution complete.
Source: <CSV_FILE>
Report: <REPORT_FILE>
Results: <N> passed, <M> failed, <K> skipped
Tokens: <INPUT_TOKENS> in / <OUTPUT_TOKENS> out (<TOTAL_TOKENS> total) — <PLAYWRIGHT_TOOL_CALLS> Playwright calls
```

## Step 7 — Close Browser

Call `mcp__playwright__browser_close` to release the Playwright browser session.

## Graceful Degradation

- Playwright MCP unavailable → error with setup instructions, no report written
- CSV file not found → error with usage hint
- CSV missing required columns → error listing missing columns
- CSV has no data rows → warning, no report written
- Invalid action value in a row → warning in report ("Unknown action: X — step skipped"), continue
- Individual step fails → screenshot + FAIL recorded, continue to next step or next test case
- Screenshot capture fails → note "screenshot unavailable", do not block execution
- `config-template.yml` missing → use defaults silently
- Output directories cannot be created → write report to CSV directory with warning
