# ADR-0003: Money as integer minor units

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-03
- **Supersedes**: —
- **Resolves**: —

## Context

Every number the user cares about in this application is an amount of money. Balances, transactions, budget limits, installment plans and gam'eya (rotating savings) shares all reduce to the same representation, and if that representation can lose a cent the user sees a wallet that does not match their own arithmetic — the one bug that destroys trust in a money app immediately.

Binary floating point cannot represent most decimal fractions exactly, so `0.1 + 0.2 !== 0.3` in PHP as in JavaScript. A sum over a few hundred transactions drifts. This rules floats out of the domain, but the risk does not stop at PHP: a `decimal` column comes back from PDO as a string, and a single implicit cast, `(float)` or `array_sum()` on the way to JSON reintroduces the same drift at the edge. The safest representation is the one that has no fractional part at all.

Currencies differ in how many decimal places they have. The target markets include EGP, SAR and AED (2 decimal places) and the Gulf currencies KWD, BHD and OMR (3). "Multiply by 100" is therefore wrong; the exponent is a property of the currency.

Splitting is a real operation, not a theoretical one: an installment plan divides a purchase into n payments, and a gam'eya divides a pot into shares. Naive division loses or invents minor units, so the parts stop summing to the whole.

The MVP has no foreign exchange, no interest and no percentage-based division, so there is no place where a genuine rounding policy has to be chosen — and no reason to build one that would later be applied by habit in the wrong place.

Liabilities are stored negative (ADR-0004), and a wallet balance is allowed to go below zero (ADR-0005), so the representation must be signed.

The wire format is fixed by [contracts/http-conventions.md](../../specs/001-architecture-constitution-adrs/contracts/http-conventions.md).

## Decision

1. Money MUST be represented in the domain by a `Money` value object in `app/Modules/Shared/Domain`, holding a signed 64-bit integer `amountMinor` and a `Currency`. It MUST be immutable; operations return a new instance.
2. `Currency` MUST be an ISO 4217 alpha-3 code, uppercase, resolved against a bundled minor-unit exponent table. EGP, SAR and AED have exponent 2; KWD, BHD and OMR have exponent 3. Code MUST NOT assume an exponent of 2.
3. Persistence MUST store an amount as `bigint amount_minor` plus `char(3) currency`. Migrations MUST NOT declare `float`, `double`, `real`, `numeric` or `decimal` columns for monetary values.
4. JSON MUST carry money as `{"amount_minor": 12345, "currency": "EGP"}`. `amount_minor` MUST be a JSON integer. It MUST NOT be a string, and MUST NOT be a float — no `"123.45"`, no `123.45`. Requests with a string or fractional `amount_minor` MUST be rejected with a validation error.
5. Arithmetic (`plus`, `minus`, comparison) MUST be performed only between two `Money` instances of the same currency. A mismatch MUST raise a `CurrencyMismatch` error; it MUST NOT be resolved by converting, by picking one side's currency, or by treating the amounts as bare integers.
6. Splitting a total MUST use `Money::allocate(array $ratios)`, which distributes by largest remainder so that the returned parts sum exactly to the original total. Installment schedules and gam'eya shares MUST be produced by `allocate`.
7. Division and rounding of amounts MUST NOT appear anywhere outside `allocate`. The `/` operator and any rounding function (`round`, `floor`, `ceil`, `intdiv`) MUST NOT be applied to a minor-unit amount elsewhere. The MVP defines no rounding policy because it has no FX, no interest and no percentage division; introducing one requires a superseding ADR.
8. Negative amounts MUST be accepted throughout. Liability wallet balances are stored and returned negative, and MUST NOT be clamped at zero or converted to a positive "owed" value by the API; presentation is the client's job.

## Alternatives Considered

### `decimal(19,4)` columns with decimal strings in PHP

The traditional accounting representation: the database stores an exact decimal and the application carries it as a numeric string. **Rejected because** PHP has no native decimal type, so every value arrives as a string and one implicit cast, arithmetic operator or `json_encode` of a computed value silently converts it to a float — the drift is reintroduced at the boundary, where it is hardest to test for. It also forces the wire format to be a string, which pushes decimal parsing into both clients.

### `moneyphp/money`

A mature library that implements exactly this model, including allocation. **Rejected because** the `Money` needed here is a small, fully specified value object in the shared kernel, and taking the dependency would add a package whose `Money::getAmount()` returns a string — clashing with the integer-JSON contract in rule 4 — and whose currency and formatting model would have to be constrained anyway to match the fixed exponent table.

### `float`/`double` amounts

The obvious default, and what a naive implementation reaches for. **Rejected because** binary floating point cannot represent decimal fractions exactly, so sums over a list of transactions drift and a balance stops matching the user's own arithmetic; no amount of display rounding fixes a stored value that is already wrong.

## Consequences

### Positive

- Sums, differences and comparisons are exact integer operations at every layer, so a balance always equals the sum of its parts.
- Splits are exact by construction: the parts of an installment plan or a gam'eya pot always add back up to the total, with the remainder distributed rather than lost.
- Currencies with 3 decimal places work without a special case, because the exponent lives in the `Currency`, not in the arithmetic.
- The wire format is unambiguous: a JSON integer has one reading, and clients cannot disagree about parsing it.
- The ban is mechanically checkable — a `float` type hint in a money path, or a `->decimal(`/`->float(`/`->double(` in a migration, is a grep away.

### Negative

- Clients and any consumer of the API must scale by the currency's exponent to display a value; there is no human-readable amount in the payload.
- `amount_minor` is a 64-bit integer, but JSON consumers in JavaScript are only safe to ±2^53, so very large values would need care — comfortably outside personal-finance ranges, but it is a constraint rather than a guarantee.
- Every construction site has to carry a currency alongside the number, which is more verbose than a bare integer and makes `CurrencyMismatch` an error path that needs handling.
- Rule 7 forbids a natural-looking operation (`/` on an amount), so it can be violated without anyone noticing in review.

### Follow-ups

- **F-4**: implement the shared kernel — `Money`, `Currency` with the exponent table, `CurrencyMismatch`, and `allocate` — with unit tests covering the 3-decimal currencies, negative amounts, and allocations whose remainder must be distributed.
- **F-1**: architecture tests plus a migration grep gate — no `float` in `Shared\Domain\Money` or money paths, and no `->decimal(` / `->float(` / `->double(` in `database/migrations`.
- Revisit when foreign exchange, interest or percentage-based division enters scope, since each requires an explicit rounding policy that this ADR deliberately omits.
