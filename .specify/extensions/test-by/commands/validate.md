---
description: "Execute acceptance scenarios from the current feature's spec.md via Playwright and produce a structured validation report"
---

# Validate Test Cases

Execute every acceptance scenario defined in the current feature's `spec.md` against a live application using Playwright browser automation. For each scenario, walk through the Given/When/Then steps, record pass/fail status and observational notes, capture a screenshot on any failure, and write a timestamped markdown report inside the feature directory.

## User Input

```text
$ARGUMENTS
```

If `$ARGUMENTS` contains a URL, treat it as the `base_url` override for this run, regardless of `config-template.yml`.

## Prerequisites

Verify the Playwright MCP server is available by attempting a lightweight call. The tools used throughout this command are:

- `mcp__playwright__browser_navigate` — navigate to a URL
- `mcp__playwright__browser_click` — click an element by text, role, or CSS selector
- `mcp__playwright__browser_fill` — fill a form field
- `mcp__playwright__browser_type` — type text into the focused element
- `mcp__playwright__browser_select_option` — select a dropdown option
- `mcp__playwright__browser_hover` — hover over an element
- `mcp__playwright__browser_press_key` — press a keyboard key
- `mcp__playwright__browser_get_visible_text` — read visible page text
- `mcp__playwright__browser_snapshot` — get accessibility snapshot of the page
- `mcp__playwright__browser_screenshot` — capture a PNG screenshot (returns base64)
- `mcp__playwright__browser_close` — close the browser when done

If the Playwright MCP server is unavailable, print a clear error and stop:
> **Error**: Playwright MCP server is not configured. Add `@playwright/mcp` to your MCP server config and retry.

## Step 1 — Locate the Spec File

1. Read `.specify/feature.json` and extract `feature_directory` (e.g. `specs/003-user-auth`).
2. If the file does not exist or `feature_directory` is empty: print an error and stop.
   > **Error**: No active feature. Run `__SPECKIT_COMMAND_SPECIFY__` first or check `.specify/feature.json`.
3. Set `FEATURE_DIR` to the resolved feature directory path.
4. Set `SPEC_FILE` to `<FEATURE_DIR>/spec.md`.
5. If `SPEC_FILE` does not exist: print an error and stop.
   > **Error**: `<SPEC_FILE>` not found. Run `__SPECKIT_COMMAND_SPECIFY__` to generate the specification.

## Step 2 — Load Configuration

1. Read `.specify/extensions/test/config-template.yml` if it exists. Use these defaults if absent:
   - `screenshot_on_failure: true`
   - `screenshot_dir: test-reports/screenshots`
   - `report_dir: test-reports`
   - `report_filename_prefix: validation`
   - `timeout_ms: 10000`
   - `retry_count: 1`
   - `base_url: null`
2. If `$ARGUMENTS` contains a URL, set `base_url` to that value (overrides config).

## Step 3 — Parse Acceptance Scenarios

Read `SPEC_FILE` and extract all acceptance scenarios using this logic:

1. Find every `**Acceptance Scenarios**:` heading inside a `### User Story N` block.
2. For each numbered item under that heading (lines starting with `1.`, `2.`, etc.):
   - Parse the Given/When/Then structure. A single item may have the form:
     - `Given [context], When [action], Then [outcome]` (inline)
     - Multi-line with `**Given**`, `**When**`, `**Then**` labels
   - A scenario may have **multiple When clauses** — each is a discrete step.
   - Extract the URL from the Given clause when present (patterns: "on [URL]", "at [URL]", "navigates to [URL]").
3. Build a flat list of **scenarios**, each with:
   - `name`: the User Story title + scenario number (e.g. "User logs in successfully — Scenario 1")
   - `slug`: kebab-case version for file naming (e.g. `user-logs-in-successfully-scenario-1`)
   - `given`: the precondition text (may include URL)
   - `when_steps`: ordered list of action strings
   - `then_assertions`: ordered list of expected outcome strings
   - `url`: extracted URL, or null if not present in Given

If no scenarios are found, print a warning and stop:
> **Warning**: No acceptance scenarios found in `<SPEC_FILE>`. Add `**Acceptance Scenarios**:` sections to your spec and retry.

## Step 4 — Prepare Output Directories

Create the output directories inside `FEATURE_DIR`:
- `<FEATURE_DIR>/<report_dir>/`
- `<FEATURE_DIR>/<screenshot_dir>/`

Generate the report filename: `<report_filename_prefix>-<YYYYMMDD-HHMMSS>.md`
Set `REPORT_FILE` to `<FEATURE_DIR>/<report_dir>/<report_filename>`.

## Step 5 — Execute Scenarios

For each scenario in the list, execute the following loop. Keep a running results ledger throughout.

### 5a. Navigate to Starting URL

Determine the URL for this scenario:
1. Use `scenario.url` if non-null.
2. Otherwise use `base_url` from config.
3. If both are null, mark the scenario SKIPPED with note "No URL provided — add a URL to the Given clause or set base_url in config-template.yml" and continue to the next scenario.

Call `mcp__playwright__browser_navigate` with the resolved URL.

If navigation fails (network error, timeout), mark scenario FAIL with the error message, capture a screenshot, and continue to the next scenario.

### 5b. Execute When Steps

For each `when_step` string in the scenario:

Determine the Playwright action by matching the step text against these patterns (case-insensitive):

| Step pattern | Tool to call | Key argument |
|---|---|---|
| `click(s) [the] "X"` or `click(s) [the] X button/link` | `mcp__playwright__browser_click` | `element: "X"` |
| `type(s) "X"` or `enter(s) "X"` | `mcp__playwright__browser_fill` | `value: "X"` |
| `select(s) "X"` or `choose(s) "X"` | `mcp__playwright__browser_select_option` | `value: "X"` |
| `hover(s) [over] "X"` | `mcp__playwright__browser_hover` | `element: "X"` |
| `press(es) [the] X key` | `mcp__playwright__browser_press_key` | `key: "X"` |
| `navigate(s) to [URL]` or `go(es) to [URL]` | `mcp__playwright__browser_navigate` | `url: "[URL]"` |
| `submit(s) [the] form` | `mcp__playwright__browser_press_key` | `key: "Enter"` |
| `wait(s) [for] N seconds` | pause (use timeout) | — |

For element identification, extract the label from quotes in the step text. If no quotes are present, use the entire non-keyword portion of the step as the element description and let Playwright resolve it by role/text.

**On step failure** (tool error, element not found, timeout):
1. If `retry_count > 0`, retry once after a brief pause.
2. If still failing, and `screenshot_on_failure` is true:
   - Call `mcp__playwright__browser_screenshot`
   - Save the returned base64 PNG to `<FEATURE_DIR>/<screenshot_dir>/<scenario-slug>-step<N>-failure.png`
   - Record the screenshot path in the failure details.
3. Mark the scenario FAIL with the step index, step text, and error message.
4. Stop processing further steps for this scenario and continue to the next.

### 5c. Verify Then Assertions

After all When steps succeed, verify each `then_assertion` string:

1. Call `mcp__playwright__browser_get_visible_text` to get the full visible text of the page.
2. For text-presence assertions ("should see X", "displays X", "shows X", "contains X"):
   - Check whether the expected text appears in the visible text.
   - If not found, also call `mcp__playwright__browser_snapshot` for an accessibility snapshot.
3. For URL assertions ("should be on [URL]", "redirects to [URL]"):
   - Check the current URL from the snapshot or page context.
4. For element-visibility assertions ("X is visible", "X appears", "X is present"):
   - Check the snapshot for the element.

**On assertion failure**:
1. Capture a screenshot (same path pattern as above, using `then<N>` instead of `step<N>`).
2. Mark the scenario FAIL with the assertion text and what was actually found.
3. Add an observation note: quote the nearest matching text from the page to help diagnose the mismatch.
4. Continue to the next scenario.

**On all assertions pass**: Mark the scenario PASS.

### 5d. Observational Notes

Regardless of pass/fail, while processing the page, note any of these automatically:

- Console errors visible in the snapshot (prefixed with "⚠ Console error observed:")
- Unexpected redirect (URL differs from expected)
- Slow page load > 5 seconds ("⚠ Slow response observed")
- Broken images or missing resources detected in the snapshot

These notes appear in the report even for passing scenarios.

## Step 6 — Write the Report

Before writing the report, collect token usage statistics for the session:
- `SCENARIOS_EXECUTED`: count of scenarios that were actually run (not skipped)
- `PLAYWRIGHT_TOOL_CALLS`: count of every `mcp__playwright__browser_*` tool call made during execution
- `INPUT_TOKENS` / `OUTPUT_TOKENS`: report these from your current session usage if available; otherwise write "N/A"
- `TOTAL_TOKENS`: sum of input and output tokens if both are available; otherwise "N/A"
- `MODEL`: the model name used for this session (e.g. `claude-sonnet-4-6`)

After all scenarios complete, write `REPORT_FILE` with this structure:

```markdown
# SpecKit Test Validation Report

**Feature**: <FEATURE_DIR>
**Spec**: <SPEC_FILE>
**Date**: <YYYY-MM-DD HH:MM:SS>
**Overall Status**: ✅ PASSED (<N> passed, 0 failed) | ❌ FAILED (<N> passed, <M> failed) | ⚠ PARTIAL (<N> passed, <M> failed, <K> skipped)

---

## Results

| # | Scenario | Status | Notes |
|---|----------|--------|-------|
| 1 | <scenario name> | ✅ PASS / ❌ FAIL / ⏭ SKIPPED | <brief note or empty> |
...

---

## Failure Details

(one section per FAILED scenario)

### <scenario name>

**Failed at**: <step description> (step <N>)

**Error**: <error message>

**Screenshot**:
![Failure screenshot](<relative path to PNG>)

**Observed page content** (nearest match):
> <quote from page>

---

## Observational Notes

(one section per scenario with notes, including passing ones)

### <scenario name>

- ⚠ <note text>

---

## Token Usage

| Metric | Value |
|--------|-------|
| Model | <MODEL> |
| Input tokens | <INPUT_TOKENS> |
| Output tokens | <OUTPUT_TOKENS> |
| Total tokens | <TOTAL_TOKENS> |
| Playwright tool calls | <PLAYWRIGHT_TOOL_CALLS> |
| Scenarios executed | <SCENARIOS_EXECUTED> |

---

## How to Re-run

```
__SPECKIT_COMMAND_TEST_VALIDATE__
```

To run against a different environment:
```
__SPECKIT_COMMAND_TEST_VALIDATE__ https://staging.example.com
```
```

Write the file to `REPORT_FILE`.

Print a brief summary to the user:

```
✅ Validation complete.
Report: <REPORT_FILE>
Results: <N> passed, <M> failed, <K> skipped
Tokens: <INPUT_TOKENS> in / <OUTPUT_TOKENS> out (<TOTAL_TOKENS> total) — <PLAYWRIGHT_TOOL_CALLS> Playwright calls
```

## Step 7 — Close Browser

Call `mcp__playwright__browser_close` to release the Playwright browser session.

## Graceful Degradation

- Playwright MCP unavailable → error with setup instructions, no report written
- `.specify/feature.json` missing → error with instructions to run `__SPECKIT_COMMAND_SPECIFY__`
- `spec.md` has no acceptance scenarios → warning, no report written
- Individual step fails → screenshot + FAIL in report; continue to next scenario
- Screenshot capture itself fails → log "screenshot unavailable" in report; do not block
- `config-template.yml` missing → use defaults silently
- Output directories cannot be created → write report to feature directory root with warning
