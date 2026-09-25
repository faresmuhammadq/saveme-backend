# Contract: Reason prompt

This makes FR-017 and FR-018 structural (R-11).

## Value object — `App\Modules\Admin\Domain\AuditReason`

```text
new AuditReason(string $value)   // trims; throws InvalidAuditReason('blank' | 'too_long'); max 500 (mb_strlen)
->value(): string
```

## Trait — `App\Modules\Admin\Http\Livewire\Concerns\RequiresReason`

This is for Livewire screens in later features.

```text
requireReason(string $actionKey, string $method, array $arguments = []): void
    // opens the prompt; stores only scalars: actionKey, method, arguments (scalars only)
confirmReason(string $reason): void
    // 1. rejects if no pending request
    // 2. new AuditReason($reason) — on InvalidAuditReason: adds a field error, prompt stays open, method NOT called
    // 3. re-runs the component's authorize() for the action (P-18 rule 7)
    // 4. calls $this->{$method}(...$arguments, reason: $auditReason)
    // 5. clears the pending request, closes the prompt
cancelReason(): void
```

- The target method's last parameter must be typed `AuditReason $reason`. If the target has no such parameter, `requireReason` throws `LogicException` at call time, which catches the mistake in the first test.
- `$method` must be **protected**. Livewire dispatches only public methods from the browser, so an audited method can't be called except through `confirmReason`. `requireReason` throws `LogicException` if the target is public. The fixture proves that a direct browser call to the audited method is rejected.
- Pending-request state holds scalars only (P-18 rule 5).

## Primitive — `<x-admin::reason-prompt>`

| Prop | Type | Notes |
|---|---|---|
| `action-key` | string | Selects the copy: `admin::reason.actions.{key}.title` and `.consequence`. If the key is missing, the translation completeness test fails |
| `tone` | default \| danger | The icon and confirm-button tone |
| `presets` | list of translation keys | Rendered as chips that fill the textarea (the operator can edit) |
| `attribution` | string | e.g. "Mariam S. · admin_action.claim.approve", in `ltr` isolation for the code |
| `confirm-label` | string | Defaults to `admin::reason.confirm` |

- **Behaviour**: a required textarea (max 500, with a live counter). Confirm is disabled while the trimmed text is empty. The server check in `confirmReason` is authoritative anyway.
- **Accessibility**: it follows the `dialog` contract; the initial focus is the textarea.
