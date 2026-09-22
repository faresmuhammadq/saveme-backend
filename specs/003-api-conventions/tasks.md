---

description: "Task list for API Conventions (003)"
---

# Tasks: API Conventions

**Input**: Design documents from `/specs/003-api-conventions/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: No automated tests. This feature changes no PHP file, so Pint, Larastan and Pest do not apply (plan Constitution Check, P-16). Validation is the structural checks and reviewer exercises in [quickstart.md](./quickstart.md), and those checks appear below as real tasks.

**Organization**: Tasks are grouped by user story so each story can be delivered and validated on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel — different file, no dependency on an incomplete task
- **[Story]**: US1 … US4, matching the user stories in [spec.md](./spec.md)
- Every task names the exact file it changes

## Path Conventions

Deliverables live in `docs/`, not `specs/` (plan → Structure Decision). Two roots:

- `docs/api-conventions.md` — the human-readable contract
- `docs/openapi/` — the machine-readable skeleton (`openapi.yaml`, `error-catalog.yaml`, `components/`, `paths/`)

Source material for every task below is in this feature's own `research.md`, `data-model.md` and `contracts/`. Where a task says "per data-model §N", transcribe that section rather than re-deciding it.

**Shared-file note**: `docs/api-conventions.md`, `docs/openapi/components/schemas.yaml`, `docs/openapi/components/headers.yaml` and `docs/openapi/error-catalog.yaml` are each touched by more than one story. Tasks on the same file are never marked `[P]`, even across phases, and the Dependencies section says which story holds the pen when.

---

## Phase 1: Setup

**Purpose**: create the file tree and the empty-but-valid documents everything else fills in

- [X] T001 Create the directory tree `docs/openapi/components/` and `docs/openapi/paths/`, with a `.gitkeep` in `docs/openapi/paths/` so the empty directory is committed
- [X] T002 [P] Create `docs/api-conventions.md` with the title, a one-line status note, a link to [docs/glossary.md](../../docs/glossary.md) and [docs/adr/](../../docs/adr/README.md), and the empty section headings in the order the later tasks fill them
- [X] T003 [P] Create `docs/openapi/openapi.yaml` with `openapi: 3.1.0`, `info` (title, summary, `version: v1.0`, contact, license), `servers` whose URLs end in `/api/v1`, `security: [{bearerAuth: []}]`, `components.securitySchemes.bearerAuth` as `type: http` / `scheme: bearer`, one `tags` entry per bounded context named in the constitution, and `paths: {}`
- [X] T004 [P] Create `docs/openapi/error-catalog.yaml` with `components.schemas.ErrorCode` and `components.schemas.FieldErrorCode` as string schemas with empty `enum` lists, an empty `x-error-catalog` map, and a header comment naming the six required fields per entry: `status`, `level`, `title`, `meaning`, `retryable`, `since`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the vocabulary every story references — base path, media types, the request identifier, and the complete error catalogue

**⚠️ CRITICAL**: no user story work starts until this phase is done. The catalogue is transcribed once here, rather than split across stories, because it is a single table in a single file and splitting it would put three stories in a merge conflict for no gain.

- [X] T005 [P] Create `docs/openapi/components/schemas.yaml` as a valid stub with `components: {schemas: {}}` and a file header naming what belongs in it
- [X] T006 [P] Create `docs/openapi/components/parameters.yaml` as a valid stub with `components: {parameters: {}}`
- [X] T007 [P] Create `docs/openapi/components/headers.yaml` as a valid stub with `components: {headers: {}}`
- [X] T008 [P] Create `docs/openapi/components/responses.yaml` as a valid stub with `components: {responses: {}}`
- [X] T009 Write the "Base path and versioning" section of `docs/api-conventions.md`: every endpoint under `/api/v1`, `v1` versions the whole surface, the number changes only for a breaking change, additive changes ship inside `v1`, no per-resource version and no version header (research R-2, FR-001)
- [X] T010 Write the "Media types and content negotiation" section of `docs/api-conventions.md`: `application/json` for success, `application/problem+json` for every failure including the `406` itself, and the refusal table — `Accept` excluding JSON is `406 NOT_ACCEPTABLE`, a non-JSON request `Content-Type` is `415 UNSUPPORTED_MEDIA_TYPE`, unparseable JSON is `400 MALFORMED_JSON`, an unknown request member is `422 VALIDATION_FAILED` with entry code `UNKNOWN_FIELD` (research R-25, FR-002)
- [X] T011 Define `X-Request-Id` (response, required, UUIDv7) and `X-Correlation-Id` (request, optional, pattern `^[A-Za-z0-9._:-]{1,128}$`) in `docs/openapi/components/headers.yaml`
- [X] T012 Write the "Request and correlation identifiers" section of `docs/api-conventions.md`: `X-Request-Id` on every response including errors, repeated as `request_id` in every problem body, and a client correlation id echoed when it matches the pattern and replaced by the server's own when it does not (research R-23, P-15 rules 2–3)
- [X] T013 Transcribe the 28 response-level codes from [data-model.md §5.1](./data-model.md) into `docs/openapi/error-catalog.yaml`: each into the `ErrorCode` enum and into `x-error-catalog` with `level: response`, its single `status`, its `title`, its one-sentence `meaning`, `retryable` as exactly one of `automatic` / `after-change` / `never`, and `since: v1.0`
- [X] T014 Transcribe the 11 field-level codes from [data-model.md §5.2](./data-model.md) into `docs/openapi/error-catalog.yaml`: each into the `FieldErrorCode` enum and into `x-error-catalog` with `level: field` and `since: v1.0`. `INVALID_ID` appears at both levels with the same meaning and is the only code that does
- [X] T015 Run the catalogue checks from [quickstart.md](./quickstart.md) §1.3 and §1.4 and confirm `catalogue and enum agree (38 codes)` and `catalogue complete`. The count is 38, not 39, because `INVALID_ID` is valid at both levels and so has one catalogue entry carrying both

**Checkpoint**: the catalogue is complete and machine-readable, and the shared documents parse. Story work can begin.

---

## Phase 3: User Story 1 — Client developer integrates an endpoint without asking anyone (Priority: P1) 🎯 MVP

**Goal**: a client developer can write request and response handling, the paging loop and the error path from the conventions alone.

**Independent Test**: hand a developer `docs/api-conventions.md` plus one feature's endpoint list. They write the types, the paging loop and the error handling with no follow-up questions (quickstart §3).

- [X] T016 [US1] Define `Envelope` and `CollectionMeta` in `docs/openapi/components/schemas.yaml`: `Envelope` requires `data` (object or array) and `meta` (object, never null); `CollectionMeta` requires `next_cursor` (string, nullable), `has_more` (boolean) and `limit` (integer, the page size actually applied after clamping) — per [data-model.md §1](./data-model.md)
- [X] T017 [US1] Define `Problem` in `docs/openapi/components/schemas.yaml` per [data-model.md §2](./data-model.md): required `type` (uri), `title` (string, not localised), `status` (integer, equal to the HTTP status), `detail` (string, localised), `code` (`$ref` `ErrorCode`) and `request_id` (uuid); optional `instance`, `errors`, `current`, `minimum_version` (semver), `retry_after_seconds` (integer). Extension members sit flat on the object, never nested under a wrapper
- [X] T018 [US1] Define `ValidationError` in `docs/openapi/components/schemas.yaml`: required `pointer` (RFC 6901 JSON Pointer into the request body, or `/$query/<name>` / `/$header/<Name>`), `code` (`$ref` `FieldErrorCode`) and `detail` (localised). One entry per offending input; entry order is not significant
- [X] T019 [US1] Define `Money` in `docs/openapi/components/schemas.yaml`: `<name>_minor` as a signed integer bounded to ±2^53−1, plus `currency` matching `^[A-Z]{3}$`. Document that a string, a float or a fractional number is refused with `AMOUNT_NOT_INTEGER` and never coerced (research R-16, P-03)
- [X] T020 [US1] [P] Define the 14 reusable problem responses in `docs/openapi/components/responses.yaml` — `BadRequest`, `Unauthenticated`, `Forbidden`, `NotFound`, `MethodNotAllowed`, `NotAcceptable`, `Conflict`, `Gone`, `UnsupportedMediaType`, `UnprocessableEntity`, `UpgradeRequired`, `TooManyRequests`, `InternalError`, `ServiceUnavailable` — each with media type `application/problem+json`, schema `Problem`, and the `X-Request-Id` header; `TooManyRequests` additionally carries `Retry-After` and the `RateLimit-*` headers (contracts/openapi-skeleton-layout.md)
- [X] T021 [US1] [P] Define the shared parameters in `docs/openapi/components/parameters.yaml`: `Cursor` (opaque string, absent means from the beginning), `Limit` (integer, default `50`, maximum `200`, values above the maximum clamped rather than refused), `Filter` (`deepObject`, `filter[<field>]=<op>:<value>`), `Sort` (comma list, `-` prefix for descending) and `ResourceId` (path, UUIDv7)
- [X] T022 [US1] Write the "Success envelope" section of `docs/api-conventions.md`: the two members, `meta` always present and `{}` for a single item, the three collection members, an empty collection as `200` with `data: []` / `has_more: false` / `next_cursor: null` and never `404` or `204`, and `204` reserved for genuinely empty responses (FR-003, FR-004)
- [X] T023 [US1] Write the "Errors" section of `docs/api-conventions.md`: RFC 9457 problem details, the `type` URL form `{base}/problems/{code-in-kebab-case}`, `title` English and stable while `detail` is localised, and the rule that clients branch on `code` only — with a wrong example beside a right one (FR-005, FR-006)
- [X] T024 [US1] Write the "Validation errors" section of `docs/api-conventions.md`: `422 VALIDATION_FAILED` with `errors[]`, the JSON Pointer form including a nested array position such as `/postings/1/date`, the `/$query/` and `/$header/` synthetic roots, and the per-entry code that lets a client react without reading prose (FR-008)
- [X] T025 [US1] Write the "Collections" section of `docs/api-conventions.md` from [contracts/collection-grammar.md](./contracts/collection-grammar.md): cursor opaque and what a client may not do with it, `limit` default 50 and maximum 200 with clamping, the seven operators, repeated fields ANDing, stabilised sort, the seven refusals, and the paging guarantee stated separately for change feeds and ordinary collections (FR-017, FR-018, FR-019, FR-020)
- [X] T026 [US1] Write the "Money, dates and numerals" section of `docs/api-conventions.md`: `<name>_minor` plus `currency` with coercion refused, calendar dates as `YYYY-MM-DD` with no timezone versus instants as UTC with `Z`, the field-naming rule that makes the two distinguishable by name, and Western numerals in all output regardless of locale (FR-021, FR-022, FR-023, FR-024). Link to the glossary's field-convention table rather than restating it
- [X] T027 [US1] Write the "Retrying" section of `docs/api-conventions.md`: how to read the catalogue's `retryable` column, why status class is the wrong signal, and the three buckets with an example code each (FR-009, research R-7)
- [X] T028 [US1] Run [quickstart.md](./quickstart.md) §1.1, §1.2 and §1.5 and confirm every YAML file parses, every `$ref` resolves, and no banned shape (`amount_cents`, `is_deleted`, `rev`, `space_id`, `updated_time`, `offset`, `page`) appears
- [X] T029 [US1] Run the seeded-violation exercise in [quickstart.md](./quickstart.md) §2 with a reviewer and confirm 5 of 5 violations are mapped to the convention they break (SC-005)
- [X] T030 [US1] Run the client-developer exercise in [quickstart.md](./quickstart.md) §3 and record every clarifying question asked as a defect against `docs/api-conventions.md` (SC-001)

**Checkpoint**: a developer can integrate a read endpoint and handle any failure from the document alone.

---

## Phase 4: User Story 2 — Offline client writes and reconciles deterministically (Priority: P1)

**Goal**: create, duplicate retry, stale version, delete-then-edit and foreign identifier each have exactly one documented outcome.

**Independent Test**: state the status, code and body for all five cases from the document alone, with no "depends on the endpoint" (quickstart §4).

- [X] T031 [US2] Define `SyncableResource` in `docs/openapi/components/schemas.yaml` per [data-model.md §6](./data-model.md): `id` (uuid), `version` (integer), `change_seq` (int64), `created_at` and `updated_at` (date-time, UTC), `deleted_at` (date-time, nullable). It **MUST NOT** declare `wallet_id` or `user_id`
- [X] T032 [US2] Define `WalletScoped` (adds `wallet_id`) and `UserScoped` (adds `user_id`) in `docs/openapi/components/schemas.yaml`. Each declares exactly one key, so a resource carrying both or neither is unrepresentable
- [X] T033 [US2] Write the "Syncable resources" section of `docs/api-conventions.md`: the six shared members, the composition rule, and the scope table — user scope is Profile, Device Session, Category and Budget; wallet scope is Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence and Invitation. State that postings are never exposed in any shape (FR-010, P-04 rule 6)
- [X] T034 [US2] Write the "Writes" section of `docs/api-conventions.md` with the nine-row outcome table from [data-model.md §6.1](./data-model.md): created `201`, updated `200`, identical replay `200` with no `version` or `change_seq` bump, stale `409`, tombstoned `409`, foreign or wrong-type id `404`, non-member `404`, non-UUIDv7 `422 INVALID_ID`, read-only `405`. Also state that a write body carries `version` (omitted or `0` means create), takes `id` from the path, and never sends `change_seq`, `created_at` or `updated_at` (FR-011, FR-013, FR-014)
- [X] T035 [US2] Write the "Version conflicts" section of `docs/api-conventions.md`: the whole write is refused and nothing is applied, `current` carries the full record under the same schema a read returns, the tombstone case returns `current` with `deleted_at` set, the server never merges, and a restore is an explicit write of `deleted_at: null` with the current `version` (FR-005a, FR-012, FR-015, ADR-0008 rules 8–9 and 14)
- [X] T036 [US2] Write the "Idempotency" section of `docs/api-conventions.md`: the header applies to exactly three non-upsert money operations — InstaPay claim submission, redemption-code redemption and Google Play purchase acknowledgement — with a key of 16–255 characters from `[A-Za-z0-9._:-]`, 24-hour retention scoped to the authenticated user, a fingerprint over method, path and canonical body, a completed match replayed verbatim, a different payload refused with `409 IDEMPOTENCY_KEY_REUSED`, and an in-flight original refused with `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` plus `Retry-After`. State why syncable creates do not carry the header (FR-027, research R-19)
- [X] T037 [US2] Add `Idempotency-Key` (request) and `Idempotency-Replayed` (response, `true` on a replay) to `docs/openapi/components/headers.yaml`
- [X] T038 [US2] Write the "Read-only resources" section of `docs/api-conventions.md`: a server-produced resource such as Category Reference appears in reads and feeds, has no write operation at all, and answers a write with `405 METHOD_NOT_ALLOWED` and an `Allow: GET` header — not `403`, which would imply an attainable permission, and not `404`, which would contradict a record the client just read (FR-016, research R-11)
- [X] T039 [US2] Run the offline-write exercise in [quickstart.md](./quickstart.md) §4 and confirm 5 of 5 cases produce one answer each (SC-004)
- [X] T040 [US2] Run the scope exercise in [quickstart.md](./quickstart.md) §5 and confirm all four user-scoped resources are expressible with `user_id` and no `wallet_id` (SC-006)

**Checkpoint**: every offline write case has one documented outcome, and the scope split holds for all eleven syncable resources.

---

## Phase 5: User Story 3 — Feature spec author extends the skeleton instead of inventing (Priority: P2)

**Goal**: an author adds an endpoint by supplying only what is unique to it, reusing the shared definitions by reference.

**Independent Test**: two unrelated endpoint definitions written against the skeleton come out with identical envelopes, error bodies, paging and headers without their authors coordinating (quickstart §6 and §7).

- [X] T041 [US3] Wire the `paths` index in `docs/openapi/openapi.yaml` so every entry is a `$ref` into `docs/openapi/paths/<feature>.yaml`, and add a comment stating that no operation, schema, parameter or response is ever defined inline in the root file
- [X] T042 [US3] Write the "Extending the skeleton" section of `docs/api-conventions.md` from [contracts/openapi-skeleton-layout.md](./contracts/openapi-skeleton-layout.md): what a feature adds (one path file, optionally catalogue entries), the five things it must not do, and the seven elements every operation declares — `operationId`, one context tag, `summary`, every success and problem response, explicit `security: []` for a public operation, and `x-filterable` / `x-sortable` on every collection operation even when empty (FR-031, FR-033)
- [X] T043 [US3] Add a worked template at `docs/openapi/paths/_template.yaml`: one collection operation and one `PUT` upsert operation that `$ref` the shared parameters, schemas and problem responses and add nothing of their own beyond the resource schema, so an author copies it rather than starting from a blank file (SC-007)
- [X] T044 [US3] Transcribe [contracts/endpoint-review-checklist.md](./contracts/endpoint-review-checklist.md) into an appendix of `docs/api-conventions.md`, because the reviewers who need it work outside `specs/`. Keep the nine groups and all 49 checks (FR-034)
- [X] T045 [US3] Write the "Source of truth" section of `docs/api-conventions.md`: where this document and `docs/openapi/` disagree the OpenAPI files win, and the disagreement is a defect fixed in the same change. State that the error catalogue is never copied into this document, only linked (FR-032)
- [X] T046 [US3] Run the extension exercise in [quickstart.md](./quickstart.md) §6, timed, and record anything that forced a change to `components/schemas.yaml` as a gap in the shared schemas (SC-007)
- [X] T047 [US3] Run the consistency check in [quickstart.md](./quickstart.md) §7 and confirm no path file spells out an envelope or problem body inline, and that no two catalogue codes describe the same condition (SC-002, SC-003)

**Checkpoint**: a feature author has a template, a rule set and a checklist, and the skeleton is the only place the shared shapes live.

---

## Phase 6: User Story 4 — Old client versions are retired without breaking users (Priority: P3)

**Goal**: the server can identify a caller's version, refuse one below the floor with a message the app can render, and warn about a retiring endpoint while it still works.

**Independent Test**: a request below the floor gets a distinct documented refusal carrying the required version; a request to a retiring endpoint succeeds while carrying a deprecation signal and its removal date.

- [X] T048 [US4] Add `X-Client-Platform` (request, required, `android` | `web`) and `X-Client-Version` (request, required, semver) to `docs/openapi/components/headers.yaml`
- [X] T049 [US4] Add the remaining response headers to `docs/openapi/components/headers.yaml`: `Content-Language`, `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` (delta-seconds), `Retry-After` (seconds), `Deprecation` (RFC 9745 structured date, `@<unix-seconds>`), `Sunset` (RFC 8594 HTTP-date), `Link` (`rel="deprecation"`) and `Allow`
- [X] T050 [US4] Write the "Client version gating" section of `docs/api-conventions.md`: both headers required on every request, a version below the floor answered `426 CLIENT_VERSION_UNSUPPORTED` with `minimum_version` in the body so the app can name the required version, an absent header `400 CLIENT_VERSION_REQUIRED`, an unparseable version or unknown platform `400 CLIENT_VERSION_INVALID`, and the gate applying to reads as well as writes (FR-029, research R-21)
- [X] T051 [US4] Write the "Rate limiting" section of `docs/api-conventions.md`: `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` on throttled routes, `429 RATE_LIMITED` with `Retry-After` on refusal, per-route budgets deferred to A4, and the implementation note that Laravel's throttle middleware emits `X-RateLimit-*` by default and the names must be overridden (FR-028, research R-20)
- [X] T052 [US4] Write the "Deprecation and removal" section of `docs/api-conventions.md`: the three headers an endpoint carries while deprecated, that it keeps working until the sunset instant and returns `410 ENDPOINT_REMOVED` after it, and that deprecation is advertised for at least one full client release cycle before that date (FR-030, research R-22)
- [X] T053 [US4] Verify in `docs/openapi/components/responses.yaml` that `UpgradeRequired` documents the `minimum_version` extension member and that `TooManyRequests` carries `Retry-After` and all three `RateLimit-*` headers; fix either if T020 left it incomplete

**Checkpoint**: the version and deprecation machinery exists from day one, so it can be relied on at the second release.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T054 [P] Add a superseded banner to the top of `specs/001-architecture-constitution-adrs/contracts/http-conventions.md` naming `docs/api-conventions.md` and `docs/openapi/` as its replacements, and stating that its `{"error": {...}}` envelope and integer `next_cursor` are dead. Keep the body as history
- [X] T055 [P] Update the A2 row in `specs/README.md` to Done, linking `docs/api-conventions.md` and `docs/openapi/openapi.yaml`, and mark the four A2 conflicts in that file as resolved by the shipped contract
- [X] T056 [P] Add one pointer line to the field-conventions section of `docs/glossary.md` sending readers to `docs/api-conventions.md` for the wire forms of those same conventions, in the style of the existing pointer in `docs/legacy/DATA_MODEL.md`
- [X] T057 Read `docs/api-conventions.md` against constitution v1.1.0 and `docs/glossary.md` and confirm it contradicts neither, does not restate the glossary's field-convention table, and does not restate the wallet permission matrix, which stays in the Wallets spec (P-08 rule 5)
- [X] T058 Re-run the whole of [quickstart.md](./quickstart.md) §1 against the finished deliverables and confirm every check passes
- [X] T059 Record follow-up F-5 in the follow-ups section of `specs/README.md`: propose an OpenAPI schema linter in CI as part of A5, since every candidate is a new dependency needing the owner's approval (research R-27)
- [X] T060 Run `/speckit-analyze` across `spec.md`, `plan.md` and `tasks.md` and resolve anything it reports before implementation is called done

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: no dependencies. T002, T003 and T004 all wait on T001 creating the directories, then run in parallel.
- **Foundational (Phase 2)**: depends on Setup. Blocks every story, because every story references a catalogue code or the request identifier.
- **User stories (Phases 3–6)**: all depend on Foundational. US1 and US2 are both P1; US1 is the MVP because a client cannot write a read path without it.
- **Polish (Phase 7)**: depends on the stories you intend to ship. T054–T056 can run as soon as the deliverables exist; T057–T060 want all four stories done.

### Story dependencies

| Story | Depends on | Notes |
|---|---|---|
| US1 (P1) | Foundational only | The MVP. Delivers the envelope, the problem body, paging and the value shapes |
| US2 (P1) | Foundational only | Independent of US1 in content, but shares `schemas.yaml`, so sequence the file (see below) |
| US3 (P2) | US1 and US2 in practice | Its template and checklist reference shapes those stories define. It can be written earlier but cannot be validated earlier |
| US4 (P3) | Foundational only | Touches `headers.yaml` and its own document sections; validating T053 wants T020 done |

### Shared-file sequencing

Four files are written by more than one phase. Nothing in them is parallel:

| File | Order |
|---|---|
| `docs/api-conventions.md` | T002 → T009, T010, T012 → T022–T027 → T033–T036, T038 → T042, T044, T045 → T050–T052 |
| `docs/openapi/components/schemas.yaml` | T005 → T016 → T017 → T018 → T019 → T031 → T032 |
| `docs/openapi/components/headers.yaml` | T007 → T011 → T037 → T048 → T049 |
| `docs/openapi/error-catalog.yaml` | T004 → T013 → T014 |

### Parallel opportunities

- T002, T003, T004 — three different files, after T001
- T005, T006, T007, T008 — four different files
- T020 (`responses.yaml`) and T021 (`parameters.yaml`) — different files; T020 needs T017 for the `Problem` schema, T021 needs neither
- T054, T055, T056 — three different files. T059 also edits `specs/README.md`, so it is **not** parallel with T055 and runs after it

### Within each story

- Schemas before the responses and parameters that reference them
- Machine-readable before human-readable: write the YAML, then describe it, so the document cannot describe something the skeleton does not contain
- Validation tasks last in the phase, and a failed check is fixed before the checkpoint is claimed

---

## Parallel Example: Phase 2

```bash
# Four component stubs, four different files:
Task: "Create docs/openapi/components/schemas.yaml as a valid stub"
Task: "Create docs/openapi/components/parameters.yaml as a valid stub"
Task: "Create docs/openapi/components/headers.yaml as a valid stub"
Task: "Create docs/openapi/components/responses.yaml as a valid stub"
```

## Parallel Example: User Story 1

```bash
# After T017 defines Problem, these two touch different files:
Task: "Define the 14 reusable problem responses in docs/openapi/components/responses.yaml"
Task: "Define the shared parameters in docs/openapi/components/parameters.yaml"
```

---

## Implementation Strategy

### MVP first (User Story 1 only)

1. Phase 1 Setup — T001–T004
2. Phase 2 Foundational — T005–T015, ending with a complete, checked error catalogue
3. Phase 3 US1 — T016–T030
4. **Stop and validate**: run the client-developer exercise. Every clarifying question is a defect in the document, not a question to answer in chat
5. At this point a feature spec can already define a read endpoint correctly

### Incremental delivery

1. Setup + Foundational → the vocabulary exists
2. US1 → reads, errors and paging are contractual (MVP)
3. US2 → offline writes and reconciliation are contractual; the eleven syncable resources are expressible
4. US3 → authors stop inventing; the template and checklist ship
5. US4 → version gating and deprecation exist before the second release needs them

### Parallel team strategy

`docs/api-conventions.md` is a single file and is the bottleneck, so two people writing prose at once will conflict. The workable split is one person on the YAML skeleton (T016–T021, T031–T032, T037, T048–T049, T053) and one on the document sections, meeting at each checkpoint. A third can take Phase 7 from T054 onward as soon as the deliverables exist.

---

## Coverage note recorded at implementation

**FR-026 (requested language and the missing-translation fallback) had no task.** The omission was
found by `/speckit-analyze` after the build. It was implemented anyway, as the "Localisation"
section of `docs/api-conventions.md` plus the `Accept-Language` request header and the
`Content-Language` response header in `docs/openapi/components/headers.yaml`, because the
requirement is backed by constitution P-14 and the deliverable would have been wrong without it.
No task was inserted after the fact; this note is the record.

Four other defects surfaced during implementation and were fixed in place: the product codename
appeared in a comment in `docs/openapi/openapi.yaml` (P-13), T015 expected 39 catalogue codes where
38 is correct, `data-model.md` §4 typed the catalogue `level` field as a single value that
`INVALID_ID` cannot use, and two of the quickstart's checks produced false failures.

## Notes

- No task changes a PHP file, adds a dependency or touches the database. If one appears to, stop — it is out of scope for this feature.
- `[P]` means a different file. Same file, same phase or not, is never parallel.
- Commit per task or per logical group; the YAML and the document section describing it belong in the same commit.
- Transcribe from `data-model.md` and `contracts/` rather than re-deciding. A disagreement with those documents is a defect to raise, not a judgement call to make while writing.
- Deferred on purpose and not to be added here: token issuance and rate-limit budgets (A4), the CI linter (A5, follow-up F-5), the sync endpoints themselves (Sync spec), batch writes, backward paging and total counts.
