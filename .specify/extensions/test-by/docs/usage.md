# Examples

This directory contains a ready-to-run example for both test commands. The example targets the public [Playwright TodoMVC demo](https://demo.playwright.dev/todomvc) — no local app setup required.

## Prerequisites

Playwright MCP server must be configured in your Claude Code settings (`.claude.json` or equivalent):

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

## Example: `todo-app`

**Target**: https://demo.playwright.dev/todomvc  
**Feature directory**: `examples/todo-app/`

### Files

| File | Used by |
|------|---------|
| `examples/todo-app/spec.md` | `/speckit-test-by-validate` |
| `examples/todo-app/test-cases/e2e.csv` | `/speckit-test-by-e2e` |

---

## Running `/speckit-test-by-validate`

This command reads `spec.md`, parses the Given/When/Then acceptance scenarios, and drives Playwright to verify each one.

The `.specify/feature.json` in this repo already points at `examples/todo-app`, so no setup is needed — just run:

```
/speckit-test-by-validate
```

**What it tests** (4 user stories, 7 scenarios):

- User Story 1 — Add a single todo; add multiple todos
- User Story 2 — Complete one todo; complete one of two todos
- User Story 3 — Filter by Active; filter by Completed
- User Story 4 — Clear all completed todos

**Output**: `examples/todo-app/test-reports/validation-<timestamp>.md`

To run against a different environment:

```
/speckit-test-by-validate https://your-staging-url.example.com
```

---

## Running `/speckit-test-by-e2e`

This command reads a CSV file and executes each row as a browser automation step.

```
/speckit-test-by-e2e examples/todo-app/test-cases/e2e.csv
```

**Test cases in the CSV** (7 test cases, ~50 steps):

| ID | Name |
|----|------|
| TC-001 | Add single todo item |
| TC-002 | Add multiple todo items |
| TC-003 | Complete a todo item |
| TC-004 | Filter active todos |
| TC-005 | Filter completed todos |
| TC-006 | Clear completed todos |
| TC-007 | Edit todo item (double-click) |

**Output**: `examples/todo-app/test-reports/e2e-<timestamp>.md`

---

## Expected Output

Both commands produce a markdown report under `examples/todo-app/test-reports/` with:

- Overall status (PASSED / FAILED / PARTIAL)
- Per-scenario or per-test-case pass/fail table
- Step-level failure details with screenshots
- Observational notes (console errors, slow loads, unexpected redirects)
- A "How to Re-run" section at the bottom
