# ADR-0010: Observability

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-15
- **Supersedes**: —
- **Resolves**: —

## Context

The support question this application actually gets is "my transaction disappeared" or "my balance is wrong", and answering it means reconstructing one user's path through a sync round-trip. That path is not one log line and not one process: a `PUT` arrives, writes under a wallet lock, dispatches events after commit ([ADR-0009](0009-domain-events-after-commit.md)), and queued listeners then run in a worker minutes later. Without a shared identifier, the HTTP entry and the listener's log line have nothing in common.

The forces:

- **Logs have to be queryable, not readable.** Grepping a text line for a wallet id works on one server and stops working as soon as there is a log aggregator. Structured lines are parsed once, at write time.
- **The correlation has to cross the queue boundary.** Laravel's `Context` is the mechanism: what is put in it is appended to every log entry and is carried into queued jobs and listeners automatically, which is exactly the HTTP → worker hop that events create.
- **Client and server logs need a join key.** A crash report from the app and the request it produced are two separate records unless the client supplies an identifier that the server echoes. The client's value cannot be trusted as-is — it is user-controlled input that ends up in log lines and dashboards.
- **This is financial data, some of it regulated in practice.** Google Play purchase tokens are credentials. InstaPay receipt images are pictures of somebody's bank app. Logging a "full request body for debugging" in a money write is how those end up in a log aggregator with a wide access list.
- **Error tracking needs a dependency.** The project does not add dependencies without approval (project convention), so the error tracker is named and scoped here but adopted in a follow-up.

The header contract — `X-Request-Id` on every response, optional `X-Correlation-Id` in, echoed out — is fixed in `specs/001-architecture-constitution-adrs/contracts/http-conventions.md`.

## Decision

1. Logs MUST be written as one JSON object per line, using Monolog's `JsonFormatter` configured through the channel's `formatter` option. Production MUST write to `stderr`. Log output MUST NOT be plain-text lines in production.
2. A middleware MUST assign every request a server-generated `request_id` (UUIDv7, per [ADR-0002](0002-identifiers-uuidv7.md)).
3. The `request_id` MUST be returned as the `X-Request-Id` response header on **every** response, including error responses, and MUST appear as `error.request_id` in the error envelope.
4. An incoming `X-Correlation-Id` MUST be accepted only when it is at most 128 characters matching `[A-Za-z0-9._:-]`. A header that fails that check MUST be discarded and the request id used in its place. A client-supplied correlation id MUST NOT be used unvalidated in a log line, a response header, or a database write.
5. The request id, the correlation id and the authenticated `user_id` MUST be put into Laravel's `Context`, so they are appended to every log entry and carried across the HTTP → queued job/listener boundary. Log calls MUST NOT re-attach these values by hand.
6. Error tracking MUST use a Sentry-compatible SDK. **It is pending dependency approval and is adopted in follow-up F-3** — no code may assume it is installed until F-3 lands.
7. The following MUST NEVER be written to logs, in any channel, at any level, including exception context and breadcrumbs:
   - passwords;
   - authentication tokens and session identifiers;
   - OTPs;
   - Google Play purchase tokens;
   - InstaPay receipt images or their contents;
   - full request bodies of financial writes.
8. For a financial write, identifiers and amounts MAY be logged at `debug` level only. They MUST NOT be logged at `info` or above.
9. Application log retention MUST be 30 days.
10. Any redaction rule added later MUST be enforced centrally (a processor or scrubber), not by asking each call site to remember.

## Alternatives Considered

### Plain-text line logs

Keep Laravel's default single-line format, readable straight from `tail`. **Rejected because** every field then has to be recovered by regex at query time, which breaks the moment a message changes wording or a value contains a space. The whole point of the request/correlation ids is filtering on them across millions of lines and across the worker boundary, and that is a field lookup in a structured store, not a grep.

### OpenTelemetry tracing now

Instrument HTTP, database and queue with OTel spans and run a collector, giving real distributed traces instead of correlated log lines. **Rejected because** it is a collector, an exporter, a backend and per-layer instrumentation for one backend service and one database — the span tree would mostly restate what a request id in `Context` already joins. It stays the right upgrade once there are several services or once latency attribution, rather than "what happened to this request", becomes the question.

### No client correlation id

Use only the server-generated request id and ignore anything the client sends. **Rejected because** it leaves no way to join an in-app crash report or a user's support screenshot to the server-side request that caused it; the user can quote a request id only if the app already captured it, which is the same problem one hop earlier. Validating the header (rule 4) removes the injection concern that motivates rejecting it.

## Consequences

### Positive

- One request id ties the HTTP entry, the write, the after-commit events and every queued listener together, which is the only way to reconstruct an asynchronous side effect after the fact.
- Every error response carries its own request id, so a user-reported failure is a direct lookup instead of a time-range search.
- JSON lines to stderr work unchanged on Laravel Cloud and any container host, with no file rotation to manage.
- `Context` means correlation is automatic: a new log call in a new module is correlated without the author doing anything.
- A single explicit never-log list makes a review question answerable — "is this field on the list?" — instead of a judgement call per call site.
- Validating `X-Correlation-Id` keeps user-controlled text out of logs and dashboards while still allowing the client join.

### Negative

- JSON lines are unpleasant to read raw in local development; a pretty-printed local channel is a separate configuration.
- Structured logs are more bytes per line than the text equivalent, so both storage and the 30-day window cost more.
- The never-log list restricts debugging: a financial write cannot be reproduced from logs alone, only from ids and amounts at `debug`.
- 30 days means an issue reported later than that cannot be investigated from logs at all.
- Error tracking is not available until F-3 is approved and implemented, so until then unhandled exceptions are only visible in the log stream.
- Every new log call site is a chance to leak a listed field; rule 10 pushes towards central scrubbing, but that scrubber has to be written.

### Follow-ups

- **F-3**: observability stack and error tracker — obtain dependency approval for the Sentry-compatible SDK, wire the channel `formatter`, the request-id middleware and the `Context` population, and configure the 30-day retention. Rules 1–6 are stated here but not enforced by code until F-3 lands.
- Revisit when the deployment grows beyond one service, or when latency attribution rather than request correlation becomes the recurring question — that is the OpenTelemetry trigger.
