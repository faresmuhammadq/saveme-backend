# SpecKit Test-By Extension

A [SpecKit](https://github.com/github/spec-kit) extension that validates feature acceptance scenarios against a live application using Playwright browser automation.

## What It Does

After you write a feature specification with `/speckit-specify`, the acceptance scenarios in `spec.md` describe exactly what the application should do. This extension executes those scenarios step-by-step in a real browser and produces a timestamped markdown report showing:

- ✅ **Passed** scenarios
- ❌ **Failed** scenarios — with the exact step that failed, the error message, and a PNG screenshot
- ⏭ **Skipped** scenarios — when a URL cannot be determined
- ⚠ **Observational notes** — console errors, slow loads, unexpected redirects

---

## Prerequisites

| Requirement | Version |
|---|---|
| [SpecKit](https://github.com/github/spec-kit) | ≥ 0.2.0 |
| Playwright MCP server | `@playwright/mcp@latest` |
| Claude Code (for Claude integration) | any |

Configure the Playwright MCP server in `.claude.json` (or your MCP config file):

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["@playwright/mcp@latest"]
    }
  }
}
```

---

## Installation

Copy the extension files into your SpecKit project, or

specify extension add test-by --from https://github.com/andstepanuk/speckit-test-validate/archive/refs/tags/v1.2.0.zip

---

## Usage

**Validate acceptance scenarios from `spec.md`:**
```
/speckit-test-by-validate
```

**With a base URL override:**
```
/speckit-test-by-validate https://staging.example.com
```

**Run CSV-driven E2E tests:**
```
/speckit-test-by-e2e
```

**With an explicit CSV path:**
```
/speckit-test-by-e2e tests/login-flow.csv
```


---

## Configuration

Edit `config-template.yml` in your project:

```yaml
# Capture PNG screenshot on step/assertion failure
screenshot_on_failure: true

# Directory for screenshots (relative to feature directory)
screenshot_dir: test-reports/screenshots

# Directory for report files (relative to feature directory)
report_dir: test-reports

# Prefix for report file names
report_filename_prefix: validation

# Milliseconds to wait for an element before marking TIMEOUT
timeout_ms: 10000

# Times to retry a failed step before recording FAIL
retry_count: 1

# Default base URL (used when a scenario's Given clause has no URL)
# Set to null to require every scenario to supply its own URL
base_url: null

# Browser settings
headless: true
viewport_width: 1280
viewport_height: 720
```

---

## Report Format

Reports are written to `<feature-directory>/test-reports/validation-<YYYYMMDD-HHMMSS>.md`.

```markdown
# SpecKit Test Validation Report

**Feature**: specs/003-user-auth
**Spec**: specs/003-user-auth/spec.md
**Date**: 2026-09-23 14:35:02
**Overall Status**: ❌ FAILED (4 passed, 1 failed)

## Results

| # | Scenario | Status | Notes |
|---|----------|--------|-------|
| 1 | User logs in successfully — Scenario 1 | ✅ PASS | |
| 2 | User sees error on wrong password — Scenario 2 | ✅ PASS | |
| 3 | User resets password via email — Scenario 3 | ❌ FAIL | "Reset link" button not found |

## Failure Details

### User resets password via email — Scenario 3

**Failed at**: When user clicks "Reset link" in email (step 2)
**Error**: Element matching "Reset link" not found after 10000ms

**Screenshot**:
![Failure screenshot](screenshots/user-resets-password-via-email-scenario-3-step2-failure.png)

**Observed page content** (nearest match):
> Click here to reset your password
```

---

## Acceptance Scenario Format

This extension reads scenarios from the `**Acceptance Scenarios**:` blocks in your `spec.md`:

```markdown
### User Story 1 - User Authentication (Priority: P1)

**Acceptance Scenarios**:

1. **Given** the user is on https://example.com/login, **When** they enter "admin@example.com" in the email field, **When** they enter "secret" in the password field, **When** they click the "Sign In" button, **Then** they should see "Welcome, Admin" on the page

2. **Given** the user is on https://example.com/login, **When** they enter "wrong@email.com" and "badpass", **When** they click "Sign In", **Then** they should see "Invalid credentials"
```

Key rules:
- Include the starting URL in the `Given` clause or set `base_url` in config
- Use plain English — the AI maps actions to Playwright tool calls
- Quote element labels: `"Sign In"`, `"Email"`, `"Submit"`
- `Then` clauses should assert visible text or URL changes

---

## Troubleshooting

**"Playwright MCP server is not configured"**
Add `@playwright/mcp` to your `.claude.json` MCP servers config (see Prerequisites).

**"No active feature"**
Run `/speckit-specify <description>` first to create a feature and `spec.md`. Then run `/speckit-test-by-validate`.

**"No acceptance scenarios found"**
Ensure your `spec.md` has `**Acceptance Scenarios**:` sections with numbered Given/When/Then items.

**Screenshots are not saved**
The `test-reports/screenshots/` directory is created automatically inside the feature directory. Check write permissions.

**Scenarios are SKIPPED**
Either add a URL to the `Given` clause (`Given the user is on https://...`) or set `base_url` in `config-template.yml`.

---

## Contributing

Issues and pull requests welcome at [github.com/andstepanuk/speckit-test-validate](https://github.com/andstepanuk/speckit-test-validate).

---

## License

MIT
