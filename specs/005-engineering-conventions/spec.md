# Feature Specification: Engineering Conventions

**Feature Branch**: `005-engineering-conventions`

**Created**: 2026-09-25

**Status**: Draft

**Input**: Backlog item A5 (see [specs/README.md](../README.md)): "Repo layout per module; the spec template (purpose · glossary · data model · invariants · API · authorization & entitlements · events · acceptance scenarios · open questions); OpenAPI is the source of truth and contract tests are generated from it; Pest with three layers (domain unit, application feature, HTTP contract); factories and seeders per module; migrations are append-only after first release; .env profiles for local/ci/staging/prod; GitLab CI stages lint → static analysis (PHPStan level 8, Pint) → test → build image; Docker Compose for local DB/Redis/Mailpit; Conventional Commits; API semver. Considerations: Tell the coding agent explicitly that every feature PR must ship: migration, OpenAPI fragment, policy, tests at all three layers, and a seeder entry if the feature has reference data." Plus the owner's note at specify time: the local stack is the already-installed `dev` CLI on Docker, and CI runs on GitHub because the repository is going to GitHub.

> **Nature of this feature.** Unlike the four foundation specs before it, this one produces **working gates as well as a document**. A convention that lives only in prose is a convention until the first busy afternoon; the ones that matter here are enforced by the pipeline, the test suite and the templates a contributor is handed. The readers are every contributor, human or agent, who opens a pull request, and the reviewer who has to decide whether it is done. Tooling already fixed by the constitution — the formatter, the static analyser, the test runner, the database engine — is the subject matter here, not leaked implementation detail. No new tool is prescribed by this specification; where one would help, it is named as a decision the owner takes at planning.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A feature pull request is complete by construction (Priority: P1)

A contributor — a person or a coding agent — implements a feature. Before opening the pull request they can read one list of what "done" means: which directories their code lives in, which pieces every feature ships, which three kinds of test it needs, and what the pipeline will refuse. They do not learn any of it from a review comment.

**Why this priority**: the repository will be built mostly by agents working from specs. An agent that is not told the definition of done will produce a different subset of it every time, and the reviewer becomes the only place the standard exists.

**Independent Test**: hand a contributor a feature spec and the conventions. Their first pull request contains every required piece, in the required place, and passes the pipeline on its first run without a reviewer naming anything missing.

**Acceptance Scenarios**:

1. **Given** a feature that adds a persisted entity, **When** the pull request opens, **Then** it contains a migration, an API definition fragment, an authorization policy, tests at all three layers, and a seeder entry if the entity has reference data — and the conventions say so before the reviewer does.
2. **Given** a contributor placing a new class, **When** they consult the conventions, **Then** exactly one directory is correct for it, and the pipeline fails if it is placed anywhere else.
3. **Given** a pull request missing one required piece, **When** the pipeline runs, **Then** it fails with a message naming the missing piece rather than a generic red cross.
4. **Given** a feature with no reference data, **When** the pull request opens, **Then** the seeder requirement is visibly not applicable rather than silently skipped.

---

### User Story 2 - The pipeline refuses what the constitution forbids (Priority: P1)

The rules the constitution and the decision records fix are checked by a machine on every push, not remembered by a person. A float column for money, a class in the wrong layer, a module reaching into another's models, a decimal amount on the wire, a test suite that ran on the wrong database engine — each one turns the pipeline red with a message that names the rule.

**Why this priority**: the constitution's own rationale for its principles is that they "erode quietly without tests". Follow-up F-1 from spec 001 promised architecture tests for three principles and nothing has shipped them. This story is where that debt is paid.

**Independent Test**: seed one violation of each enforced rule into a branch. Every one fails the pipeline, and the failure output names the rule it broke.

**Acceptance Scenarios**:

1. **Given** a migration declaring a floating-point or decimal column for a money value, **When** the pipeline runs, **Then** it fails naming the money principle.
2. **Given** a domain class importing the persistence layer, or a module importing another module's persistence or HTTP namespace, **When** the pipeline runs, **Then** it fails naming the boundary rule and the offending import.
3. **Given** a capability check that compares a plan code outside the entitlement resolution, **When** the pipeline runs, **Then** it fails naming the entitlement principle.
4. **Given** an API definition that does not validate, references an undefined shared component, or uses an error code absent from the catalogue, **When** the pipeline runs, **Then** it fails naming the offending file.
5. **Given** a test run, **When** it completes, **Then** the pipeline can prove it ran against the production database engine and not a substitute.

---

### User Story 3 - A new contributor is productive in one sitting (Priority: P2)

Someone clones the repository on a machine with the local stack installed. They start the environment, fill their configuration, run the suite, and see it pass — following one page, in the order written, with no tribal knowledge. Every command they need is named, and every environment the code can run in has a stated configuration.

**Why this priority**: a project meant to be built by agents needs its setup to be mechanical. If starting the environment needs a conversation, every new session starts with the same conversation.

**Independent Test**: on a fresh clone with the stack installed, a contributor follows the setup page from top to bottom and has a green suite in under thirty minutes without asking anyone anything.

**Acceptance Scenarios**:

1. **Given** a fresh clone, **When** the contributor follows the setup page, **Then** the environment starts, configuration is filled from the shared stack, migrations run and the suite passes.
2. **Given** the four places the application runs — a contributor's machine, the pipeline, a staging deployment, production — **When** a contributor asks which configuration applies where, **Then** each has a named profile stating what differs and what may never differ.
3. **Given** a contributor who needs the database, cache, or mail catcher, **When** they look for it, **Then** the conventions name the shared service, how to reach it, and how to inspect it.
4. **Given** a second worktree of the same repository, **When** it is started, **Then** it gets its own environment and its own database without colliding with the first.

---

### User Story 4 - A feature author writes a specification in the project's shape (Priority: P2)

Someone specifying Wallets, Budgets or Billing is handed a template whose sections are the ones this project needs — purpose, glossary terms, data model, invariants, API, authorization and entitlements, events, acceptance scenarios, open questions — rather than a generic one they have to reshape. Two specs written months apart have the same bones.

**Why this priority**: four foundation specs have now each invented their own sub-structure. The feature specs that follow will number in the teens; the cost of inconsistency compounds from here.

**Independent Test**: take two unrelated feature specs written against the template. A reviewer finds every one of the nine sections in both, in the same order, and can locate a feature's invariants or events without reading the whole document.

**Acceptance Scenarios**:

1. **Given** the template, **When** an author starts a feature spec, **Then** the nine project sections are present as headings with a one-line prompt each, and the mandatory sections the specification workflow requires are preserved.
2. **Given** a term the author needs that is not in the glossary, **When** they write the spec, **Then** the template tells them to add it to the glossary first, per the glossary's own rule.
3. **Given** an invariant a feature introduces, **When** the spec is reviewed, **Then** it is listed in the invariants section with the test that will fail if it is broken.

---

### User Story 5 - History and versions are legible (Priority: P3)

A commit message says what changed and why in a fixed shape, so a changelog can be assembled from history. A release of the API states its version in a way that tells a client whether it can keep working.

**Why this priority**: it matters from the second release, not the first, but a history that was not shaped from the start cannot be reshaped later.

**Independent Test**: given the last twenty commits, a reviewer can classify each as a feature, a fix, a chore or a documentation change from its first line alone, and a script can group them into a changelog without human editing.

**Acceptance Scenarios**:

1. **Given** a commit that does not follow the message shape, **When** it is pushed to a pull request, **Then** the pipeline reports it.
2. **Given** an additive change to the API, **When** a release is cut, **Then** the document version increments in a way that distinguishes it from a breaking change, and the base path does not move.
3. **Given** a breaking change to the API, **When** a release is cut, **Then** the conventions say what must happen to the base path, the deprecation headers and the client floor.

---

### Edge Cases

- **Enforcement with nothing to enforce yet**: the module directories do not exist. Architecture tests must pass vacuously today and bite the moment the first module lands, not fail on an empty tree.
- **A gate that needs a tool the owner has not approved**: several checks in this feature are cheapest with a new package. The constitution requires the owner's approval for any dependency; a convention must not smuggle one in as a test helper.
- **The analyser level the backlog wants is not the level the constitution fixes**: the description says level 8; the constitution and the configuration say 7. A conventions document cannot resolve that on its own.
- **Migrations after the first release**: "append-only" has no meaning until something has been released. The conventions must define the moment it starts applying and what a contributor does with a schema mistake after it.
- **A worktree that shares a database by accident**: two worktrees pointed at one database corrupt each other's test runs. The per-worktree environment must be the default, not an option.
- **The pipeline environment drifting from the local one**: a suite that passes locally on one database version and in the pipeline on another is two suites. The engine and its major version must be pinned in both places to the same value.
- **A pull request from an agent that has never seen the conventions**: the definition of done has to be reachable from the repository root and from the project instructions the agent is handed, or it exists only for humans.
- **An OpenAPI change with no path file**: a feature that edits shared components instead of adding its own path file is exactly what the API conventions forbid, and the pipeline is the only place that can catch it reliably.
- **A staging and a production profile before either exists**: the deployment target is undecided. The profiles must be defined by what differs, so they hold whatever the target turns out to be.

## Requirements *(mandatory)*

### Functional Requirements

#### Layout and placement

- **FR-001**: The conventions MUST restate the module directory layout the constitution fixes, and MUST state for each kind of class — value object, entity, repository interface, application service, query service, model, policy, controller, request, resource, job, listener, event — exactly one directory it belongs in.
- **FR-002**: Every test MUST live in one of three layers, named for what it exercises: domain rules with no framework, application services against a real database, and HTTP endpoints against the API contract. The conventions MUST define what each layer may and may not touch.
- **FR-003**: Each module MUST own its factories and seeders, placed inside the module rather than in a shared location, and the conventions MUST state how a seeder registers itself so the application can find it.

#### The definition of done

- **FR-004**: The conventions MUST state, as one checklist, what every feature pull request ships: a migration for each persisted change, an API definition fragment for each endpoint, an authorization policy for each resource, tests at all three layers, a seeder entry when the feature has reference data, and a glossary entry for any new term.
- **FR-005**: Each item on that list MUST be either checked by the pipeline or explicitly marked as reviewer-checked, and the conventions MUST NOT leave an item that nobody checks.
- **FR-006**: The definition of done MUST be reachable from the repository root and from the instructions a coding agent is handed, so an agent that has read neither the constitution nor a spec still finds it.

#### Enforcement

- **FR-007**: The pipeline MUST run, in order: formatting, static analysis, the test suite, and the API definition checks. A failure at any stage MUST stop the later stages and MUST name the stage and the rule.
- **FR-008**: Architecture tests MUST enforce the module-boundary principle: no domain class imports the persistence layer, no module imports another module's persistence or HTTP namespace, and the shared kernel imports no module. They MUST pass on an empty module tree and fail on the first violation.
- **FR-009**: A migration gate MUST fail the pipeline when a migration declares a floating-point or decimal column type, naming the money principle.
- **FR-010**: An entitlement gate MUST fail the pipeline when a plan code is compared anywhere outside the entitlement resolution, naming the entitlement principle.
- **FR-011**: The API definition MUST be checked on every push: it parses, every reference resolves, every error code an operation uses exists in the catalogue, and no operation defines a problem response or an envelope inline. The check MUST fail naming the file and the rule.
- **FR-012**: The test suite MUST run against the production database engine in the pipeline and locally, pinned to the same major version in both, and the pipeline MUST record which engine and version the run used.
- **FR-013**: The static analyser MUST run at **level 8**. Because the constitution currently fixes level 7, this feature MUST ship the constitution amendment to P-16 rule 1 in the same change as the configuration change, with the version bump the governance section requires, so the two never disagree.
- **FR-014**: Contract tests MUST prove each endpoint's responses conform to its API definition, including error responses, by validating the actual response against the definition for its path, status and media type — not by hand-written shape assertions that can drift from the definition. The owner has approved one development-only validator dependency for this; planning names the package and lists it for final sign-off.
- **FR-014a**: The same validator MUST lint the API definition itself in the pipeline — parse, references, catalogue membership — which closes follow-up F-5 deferred from the API conventions.

#### Environments

- **FR-015**: The conventions MUST document the local environment as the installed per-worktree tool on the shared stack: how to start it, fill configuration, run commands inside it, reach the database, cache and mail catcher, and run the suite. The conventions MUST NOT describe a second way to run locally.
- **FR-016**: Each worktree MUST get its own environment and its own database by default, and the conventions MUST state how to copy one worktree's database into another.
- **FR-017**: There MUST be four named configuration profiles — local, pipeline, staging, production — each stated as what differs from the others and what may never differ, and the conventions MUST name the one place secrets live for each.
- **FR-018**: Production and staging profiles MUST be defined by what differs and what may never differ, without assuming a deployment target, because none is decided. The pipeline MUST NOT include an image-build stage until a target is chosen; the feature that chooses the target adds the stage, names the secret store, and fills the two profiles' hosts.

#### Specifications

- **FR-019**: The feature specification template MUST carry the nine project sections — purpose, glossary terms, data model, invariants, API, authorization and entitlements, events, acceptance scenarios, open questions — in that order, each with a one-line prompt, while preserving the sections the specification workflow requires.
- **FR-020**: The template MUST instruct the author to add any new term to the glossary before using it, and MUST require each invariant to name the test that fails when it is broken.
- **FR-021**: The conventions MUST state the decision-record process as the constitution fixes it — a new record supersedes, an accepted one is never edited — and when a feature needs one.

#### Schema and history

- **FR-022**: Migrations MUST be append-only from the first tagged release onward. The conventions MUST define that moment, and MUST define how a schema mistake is corrected afterwards without editing a shipped migration.
- **FR-023**: Commit messages MUST follow a fixed shape with a type, an optional scope and a subject, and the pipeline MUST report a pull request whose commits do not.
- **FR-024**: The API document MUST carry a version that distinguishes an additive change from a breaking one, and the conventions MUST state that the base path moves only on a breaking change, consistent with the API conventions already in force.

#### Scope of this feature

- **FR-025**: Every convention here MUST be either enforced by the pipeline or expressible as a check a reviewer applies to one pull request. A convention that is neither is a defect in this document.

### Key Entities

- **Layer**: one of the three test layers, with what it may touch and what it may not.
- **Definition of Done**: the checklist a feature pull request satisfies, each item tagged with who checks it — pipeline or reviewer.
- **Gate**: one pipeline check that names a rule when it fails.
- **Profile**: one of the four configurations, stated as what differs and what may never differ.
- **Worktree Environment**: one running local environment bound to one checkout and one database.
- **Feature Spec Template**: the nine-section shape every feature specification takes.
- **Commit Type**: one of the fixed set a commit's first line begins with.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A contributor given a feature spec and the conventions opens a first pull request that contains every required piece and passes the pipeline on its first run, with zero reviewer comments naming a missing piece.
- **SC-002**: Given one seeded violation of each enforced rule — a decimal money column, a domain class importing persistence, a cross-module model import, a plan-code comparison outside entitlements, an API definition with a dangling reference, an invented error code — the pipeline fails 6 of 6 and each failure names its rule.
- **SC-003**: The architecture tests pass on the current empty module tree and fail within one pipeline run of the first violating class being added.
- **SC-004**: A new contributor on a fresh clone reaches a green test suite in under thirty minutes following the setup page, asking nobody anything.
- **SC-005**: Two feature specs written by different authors against the template contain all nine project sections in the same order, and a reviewer locates any one section in either spec in under a minute.
- **SC-006**: From the first twenty commits after this feature lands, a script assembles a changelog grouped by type with no human editing, and a reviewer classifies every commit correctly from its first line alone.
- **SC-007**: The four configuration profiles cover every setting the application reads, and no setting exists that is in one profile without a stated value or a stated "must not be set" in the other three.
- **SC-008**: Every item on the definition of done has exactly one checker, pipeline or reviewer, and a reviewer reading the list finds no item that nobody checks.

## Clarifications

### Session 2026-09-25

- Q: The backlog asks for static analysis at level 8; the constitution and the configuration fix level 7. → A: **Level 8, amending P-16 in the same change.** Adopted before any code exists so nothing is retrofitted. The constitution amendment ships with the configuration change and the version bump governance requires.
- Q: Contract conformance by a validator package, by hand-written assertions, or deferred? → A: **A development-only OpenAPI validator, approved by the owner.** Planning names the package and lists it for final sign-off. It also lints the skeleton in the pipeline, which closes follow-up F-5 from spec 003.
- Q: The backlog wants a "build image" stage and staging and production profiles; what is the deployment target? → A: **Undecided.** Profiles are written by difference so they hold for any target; the pipeline ships four stages with no image build; the feature that picks the target adds the stage and names the secret store.

## Assumptions

- The local stack is the installed per-worktree `dev` tool on Docker, already running here with a shared PostgreSQL 16, Redis 7 and mail catcher, and a proxy that resolves `*.test` hostnames. The conventions document what exists rather than proposing a second stack; the backlog's Docker Compose suggestion is superseded by the owner's note.
- The pipeline runs on GitHub Actions, per the owner's note that the repository is going to GitHub. The existing workflow that runs the suite against PostgreSQL 16 is extended, not replaced. The backlog's GitLab stages are superseded.
- Formatting and static analysis tools are the ones the constitution already names. The analyser's level changes to 8 and the constitution changes with it; nothing else about the tools changes, and their configuration files stay where they are.
- The database engine and major version are the ones the database decision record fixes. The pipeline's service and the shared stack's service are both that version already, so pinning is a matter of writing the number down in both places.
- The specification workflow's own mandatory sections stay as they are; the nine project sections are added to the template as a project-specific block, so the workflow's tooling keeps working.
- "Append-only" begins at the first tagged release. Until then a migration may be edited or squashed, because nothing has been deployed that a migration could break.
- A commit message shape is adopted for the subject line only; a body is free text. The types are the common fixed set: feature, fix, docs, chore, refactor, test, build, ci.
- The API document's version follows the major-and-minor form already written into the skeleton, where the minor number moves on an additive change and the major number moves only with the base path, consistent with the API conventions.
- Architecture tests are ordinary tests in the existing runner, so they need no new dependency to exist. Whether a dedicated architecture-testing package makes them cheaper is a planning decision the owner takes, not a requirement here.
- Exactly one new development-only dependency is approved by this specification: the OpenAPI validator. Any other package planning finds useful is a separate approval.
- A "build image" stage is not defined until the deployment target is; the pipeline's four stages are complete without it, and the stage is added by the feature that adds the target.
- Nothing in this feature changes application behaviour. It adds gates, documents, scripts and a template.
