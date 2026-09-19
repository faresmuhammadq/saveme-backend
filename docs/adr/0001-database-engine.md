# ADR-0001: Database engine

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-16
- **Supersedes**: —
- **Resolves**: —

## Context

The schema and the runtime behaviour this application needs are not engine-neutral:

- Every primary key is a UUIDv7 (ADR-0002), so the engine should store it as a native 16-byte `uuid` rather than a `CHAR(36)` string or a `BINARY(16)` blob that Eloquent has to encode and decode.
- Instants are stored in UTC with microsecond precision (ADR-0011), which wants a real `timestamptz`.
- Syncable entities use soft deletes, and the uniqueness rules ("one wallet name per owner", "one active membership per user per wallet") must ignore tombstoned rows. That is a partial index (`WHERE deleted_at IS NULL`).
- The sync counter and the balance cache both serialise writers by taking `SELECT … FOR UPDATE` on the scope row (`lockForUpdate` in Laravel). A shared wallet has concurrent writers by design, so row-level locking has to work under concurrency, not just in theory.
- Plan features are a bag of flags and quotas read as data (ADR-0007), which wants `jsonb`.
- Invariants such as `length(currency) = 3` belong in the schema as `CHECK` constraints.
- Migrations should roll back cleanly on failure, which needs transactional DDL.

The second force is the test suite. A suite that runs on a different engine from production cannot prove the behaviour above: row locks, timestamp semantics, partial indexes and `jsonb` are exactly where engines differ. P-16 makes tests on the production engine a merge gate, so the engine choice and the test-database choice are one decision, not two.

Deployment target is Laravel Cloud, with every other managed host as a fallback.

## Decision

1. The application MUST use PostgreSQL 16 or later as its only relational database engine.
2. Every environment — local development, CI, staging and production — MUST run PostgreSQL 16+. The automated test suite MUST run against PostgreSQL; it MUST NOT run against SQLite or any other engine.
3. Migrations MUST use the native `uuid` column type for identifiers and `timestampTz` for instants. They MUST NOT store UUIDs as `char`/`varchar`/`binary`, and MUST NOT use `timestamp` without a time zone for instants.
4. Uniqueness rules that must ignore soft-deleted rows MUST be expressed as partial indexes (`WHERE deleted_at IS NULL`), not enforced only in application code.
5. Code that serialises concurrent writers (the sync counter in ADR-0008 and the balance cache in ADR-0005) MUST take the row lock with `lockForUpdate()` inside a database transaction.
6. Plan feature bags MUST be stored in a `jsonb` column.
7. Schema-level invariants that the engine can check (for example `char_length(currency) = 3`) MUST be added as `CHECK` constraints in the migration, in addition to any application validation.
8. A feature MUST NOT be merged with a green suite that ran on an engine other than PostgreSQL.

## Alternatives Considered

### MySQL 8

The most widely hosted alternative, and the Laravel default in many templates. **Rejected because** it has no native `uuid` type (forcing `BINARY(16)` with application-side encoding, or a 36-character string that doubles index size), no partial indexes (so "unique among non-deleted rows" cannot be enforced by the schema), a much shorter and less consistent history of enforcing `CHECK` constraints, and no `jsonb` equivalent with the same indexing story.

### SQLite

Zero-setup, and an in-memory database makes the test suite very fast. **Rejected because** its single writer lock serialises the whole database, which is the opposite of the per-scope row locking that shared wallets depend on; it is not a production host target, so using it only for tests would mean the suite never exercises the locking, timestamp and index behaviour the application actually relies on — the precise class of bug that reaches production silently.

## Consequences

### Positive

- One engine everywhere: a test that passes proves behaviour on the production engine, including `lockForUpdate` contention, `timestamptz` handling and partial-index uniqueness.
- UUID primary keys cost 16 bytes and compare natively, keeping indexes small.
- Invariants can be pushed into the schema (`CHECK`, partial unique indexes), so a bug in one code path cannot corrupt data written through another.
- Transactional DDL means a failed migration leaves no half-applied schema.
- Available on Laravel Cloud and every major managed host, so the choice does not constrain deployment.

### Negative

- Local development and CI now need a running PostgreSQL service instead of a file. Provisioning `.env.example`, `phpunit.xml` and the CI workflow is follow-up F-2; until it lands, the repository's dev and test configuration still points at SQLite.
- `RefreshDatabase` against PostgreSQL is slower than an in-memory SQLite database. Mitigated by Laravel's parallel testing, which creates one suffixed test database per process, so wall-clock time scales with cores.
- Contributors must have PostgreSQL installed (or run it in a container) before the suite will run at all.

### Follow-ups

- **F-2**: switch dev, test and CI to PostgreSQL — `.env.example`, `phpunit.xml`, and a Postgres service in the CI workflow. Until F-2 lands, rule 2 is stated but not yet enforced by the tooling.
- Revisit if a managed host that is otherwise required drops PostgreSQL support, or if parallel test wall-clock time becomes a merge bottleneck.
