# Contract: ADR File

Path: `docs/adr/NNNN-kebab-title.md`. All sections are required. Copy this template to `docs/adr/template.md`.

```markdown
# ADR-NNNN: <Title>

- **Status**: Proposed | Accepted | Rejected | Deprecated | Superseded by [ADR-NNNN](NNNN-title.md)
- **Date**: YYYY-MM-DD
- **Principles**: P-NN[, P-NN]
- **Supersedes**: — | [ADR-NNNN](…)
- **Resolves**: — | O1

## Context

<Problem, forces, constraints from the brief. No decision here.>

## Decision

<The choice, as numbered MUST / MUST NOT rules a reviewer can check.>

## Alternatives Considered

### <Alternative A>
<Summary.> **Rejected because** <reason>.

### <Alternative B>
<Summary.> **Rejected because** <reason>.

## Consequences

### Positive
- …

### Negative
- …

### Follow-ups
- <Work or future triggers to revisit this decision.>
```

## Validation rules

- The heading number matches the file name number.
- At least 2 `### ` subsections under Alternatives, each containing "Rejected because".
- At least 1 bullet under Positive and at least 1 under Negative.
- `Principles` lists at least one existing `P-NN`.
- A Superseded status link points to an existing ADR whose `Supersedes` points back.
