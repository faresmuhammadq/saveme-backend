# API Conventions

**Status**: Active. This is the contract every endpoint in this API obeys. It is written for the Android client team, the landing-page team, backend contributors and anyone specifying a feature.

**Companions**: [docs/glossary.md](glossary.md) names the entities and fixes the field conventions. [docs/adr/](adr/README.md) records why each decision was taken. [`docs/openapi/openapi.yaml`](openapi/openapi.yaml) is the machine-readable form of everything below, and where the two disagree, it wins — see [Source of truth](#source-of-truth).

---

## Base path and versioning

Every endpoint lives under `/api/v1`. There is no per-resource version and no version header.

`v1` versions the **whole surface**, not one resource. The number changes only when a change would break a client already in the field. Everything additive ships inside `v1`:

| Change | Inside `v1`? |
|---|---|
| A new endpoint | yes |
| A new optional request member | yes |
| A new response member | yes |
| A new error code | yes |
| A new optional query parameter | yes |
| Removing or renaming a response member | no |
| Making an optional request member required | no |
| Changing a field's type or a code's status | no |
| Narrowing an enum a client already reads | no |

A client is expected to ignore response members it does not recognise. A client that fails on an unknown member turns every additive change into a breaking one.

Retiring an endpoint inside `v1` is possible and has its own process — see [Deprecation and removal](#deprecation-and-removal).

## Media types and content negotiation

JSON only, in both directions.

| Direction | Media type |
|---|---|
| Request body | `application/json` |
| Successful response | `application/json` |
| Any failure | `application/problem+json` |

A problem body is always sent as `application/problem+json`, even when the request's `Accept` header did not name it, and even on the `406` that refuses the `Accept` header itself. A client that cannot parse the problem media type still gets a readable JSON body.

Refusals:

| Situation | Status | Code |
|---|---|---|
| `Accept` excludes `application/json` | `406` | `NOT_ACCEPTABLE` |
| Request `Content-Type` is not `application/json` on a method with a body | `415` | `UNSUPPORTED_MEDIA_TYPE` |
| The body is not parseable JSON | `400` | `MALFORMED_JSON` |
| The body is valid JSON but not an object where one is required | `400` | `MALFORMED_JSON` |
| The body carries a member that is not part of the request shape | `422` | `VALIDATION_FAILED`, entry code `UNKNOWN_FIELD` |

Unknown request members are **refused, not ignored**. A client that renames a field on its side would otherwise keep writing successfully while silently dropping data, and nobody would learn until a user noticed a missing note.

## Request and correlation identifiers

Every response carries `X-Request-Id`, a server-generated UUIDv7. Success or failure, read or write, it is always there.

Every problem body repeats the same value as `request_id`. That repetition is deliberate: a user pastes an error screen into a support chat, and a response header is not on that screen.

A client may send its own `X-Correlation-Id` to join its logs to the server's:

| Case | Behaviour |
|---|---|
| Matches `^[A-Za-z0-9._:-]{1,128}$` | Echoed back unchanged, and propagated to logs, queued jobs and listeners |
| Absent, too long, or contains anything else | Discarded silently; the server's own request id is used instead |

A malformed correlation id is never an error. The request proceeds; only the correlation is lost.

## Success envelope

Every successful response with a body has exactly two top-level members.

```json
{ "data": …, "meta": { … } }
```

`data` is the resource for a single-item response, or an array for a collection. `meta` is **always present and never null**, so a client parses one generic `Envelope<T>` with no "absent or empty" branch. For a single item it is `{}` unless the endpoint documents a member.

A collection's `meta` carries three members:

| Member | Type | Meaning |
|---|---|---|
| `next_cursor` | string or null | opaque token for the next page; null on the last page |
| `has_more` | boolean | false exactly when `next_cursor` is null |
| `limit` | integer | the page size actually applied, after clamping |

`has_more` is one redundant bit on purpose: the last-page test is explicit rather than inferred from a null.

Distinguishing the four cases a client cares about:

| Case | Status | Body |
|---|---|---|
| Record created | `201` | the resource in `data` |
| Record updated | `200` | the resource in `data` |
| Repeated write returning the record unchanged | `200` | the resource in `data`, with the same `version` and `change_seq` as before |
| Empty collection | `200` | `data: []`, `has_more: false`, `next_cursor: null` |

An empty collection is never `404` and never `204`. `204 No Content` is used only where there is genuinely nothing to return.

Failures do not use this envelope. They are problem details at the top level — see [Errors](#errors).

## Errors

Every failure is an [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details document, served as `application/problem+json`.

```json
{
  "type": "https://api.example/problems/feature-not-entitled",
  "title": "Feature not entitled",
  "status": 403,
  "detail": "مشاركة المحفظة غير متاحة في باقتك الحالية.",
  "code": "FEATURE_NOT_ENTITLED",
  "request_id": "0192f7a0-9999-7000-8000-00000000abcd"
}
```

The four standard members, plus extension members that sit **flat beside them**, never nested under a wrapper:

| Member | Always? | What it is |
|---|---|---|
| `type` | yes | dereferenceable URL of this code's catalogue entry, `{base}/problems/{code-in-kebab-case}` |
| `title` | yes | short English label from the catalogue. Stable across locales, never localised |
| `status` | yes | equal to the HTTP status |
| `detail` | yes | human sentence, localised |
| `code` | yes | stable `UPPER_SNAKE` identifier |
| `request_id` | yes | same value as the `X-Request-Id` header |
| `instance` | sometimes | the request path, when it adds anything |
| `errors` | validation only | one entry per offending input |
| `current` | `VERSION_CONFLICT` only | the record's present state |
| `minimum_version` | `CLIENT_VERSION_UNSUPPORTED` only | the version to update to |
| `feature` | `FEATURE_NOT_ENTITLED`, from v1.9 | the capability key the plan lacks or whose quota is spent, e.g. `sharing.enabled`, `sharing.max_members_per_wallet`, `wallets.max` |
| `retry_after_seconds` | when `Retry-After` is sent | a mirror of that header |

### Branch on `code`, never on the message

`code` is the only member a client may test. `title` is a label for a human reading a log; `detail` is a sentence for a human reading a screen, and it changes with the locale and with any copy edit.

```kotlin
// Wrong — breaks the first time the copy changes, and always in Arabic
if (problem.detail.contains("not entitled")) { showUpgrade() }

// Right
if (problem.code == "FEATURE_NOT_ENTITLED") { showUpgrade() }
```

`type` exists so a human can follow the link from a log line to the catalogue entry. It is not the branch: a URL comparison is a string compare with more ways to go wrong.

### One catalogue

Every code lives in [`docs/openapi/error-catalog.yaml`](openapi/error-catalog.yaml), with its status, its English title, its meaning, whether it is retryable, and the release it appeared in. A feature adds a code there; it never invents a local string. An operation may only reference a code that exists in the catalogue.

A code maps to exactly one status. Two situations that need different statuses need two codes. A released code is never deleted or reused for another meaning — it is deprecated in place, pointing at its replacement.

## Validation errors

A validation failure is `422` with `code: VALIDATION_FAILED` and an `errors` array carrying one entry per offending input.

```json
{
  "type": "https://api.example/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request has 2 invalid fields.",
  "code": "VALIDATION_FAILED",
  "request_id": "0192f7a0-…",
  "errors": [
    { "pointer": "/amount_minor", "code": "AMOUNT_NOT_INTEGER",
      "detail": "Amount must be an integer number of minor units." },
    { "pointer": "/postings/1/date", "code": "DATE_NOT_CALENDAR_DATE",
      "detail": "Date must be YYYY-MM-DD with no time or offset." }
  ]
}
```

### `pointer`

An [RFC 6901](https://www.rfc-editor.org/rfc/rfc6901) JSON Pointer into the request body, rooted at the body itself. Array positions are numeric segments, which is the case that breaks a dotted path: `/postings/1/date` is unambiguous where `postings.1.date` is not, and a field name is allowed to contain a dot.

A failure outside the body points into a synthetic document:

| Source | Pointer |
|---|---|
| Request body member | `/amount_minor`, `/postings/0/amount_minor` |
| Query parameter | `/$query/limit` |
| Request header | `/$header/Idempotency-Key` |

A client maps a pointer to an input control mechanically, which is what makes "highlight the field that is wrong" a mechanical job rather than a parsing exercise.

### `code` per entry

Each entry carries its own field-level code, so a client reacts to `AMOUNT_NOT_INTEGER` without reading prose. The field-level codes are in the same catalogue, under `FieldErrorCode`.

Two distinct failures on the same input produce two entries. Entry order is not significant and a client must not depend on it.

## Collections

Every collection endpoint — including a change feed — uses the same four query parameters and the same refusals.

| Parameter | Form | Default |
|---|---|---|
| `cursor` | opaque string | absent, meaning from the beginning |
| `limit` | integer 1–200 | `50` |
| `filter[<field>]` | `<op>:<value>`, or a bare value meaning `eq` | none |
| `sort` | comma list, `-` prefix for descending | per endpoint |

### Cursors are opaque

A client stores what `meta.next_cursor` gave it and sends it back unchanged. It never parses, compares, increments, constructs or reuses one across endpoints. The server is free to change what a cursor encodes without a client release, which is the whole point of it being opaque.

A cursor is valid only for the endpoint that issued it, and only with the same `filter` and `sort`. Changing either invalidates it and the client starts a new walk.

### `limit`

A value above the maximum is **clamped, not refused**, and `meta.limit` reports what was applied. A client bug throttles instead of breaking a sync loop, and the clamp is visible rather than silent. A non-integer, or a value below 1, is `400 INVALID_QUERY_PARAMETER`.

### Filtering

```text
?filter[date]=gte:2026-01-01&filter[date]=lte:2026-01-31&filter[wallet_id]=0192f79e-…
```

Operators: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in` (comma-separated values). A bare value means `eq`. Repeating a field ANDs its conditions. There is no `or`, no grouping, and no free-text query language — an unbounded query language over a money table gives unreviewable query plans.

Each endpoint declares what it allows in `x-filterable` and `x-sortable` on its operation, and both keys are present even when empty. Anything outside the list is **refused, never ignored**: a silently dropped filter returns a plausible page of the wrong rows, which is a wrong answer that looks right.

### Sorting

```text
?sort=-date,id
```

Descending by `date`, then ascending by `id`. Every sort is stabilised — the server appends `id` when the client has not — so the keyset the cursor walks is total and a page boundary cannot repeat or skip rows that share a sort value.

### Refusals

| Situation | Status | Code |
|---|---|---|
| Field not in `x-filterable` | `400` | `FILTER_FIELD_NOT_SUPPORTED` |
| Operator not allowed for that field | `400` | `FILTER_OPERATOR_NOT_SUPPORTED` |
| Value not parseable as the field's type | `400` | `INVALID_QUERY_PARAMETER` |
| Field not in `x-sortable` | `400` | `SORT_FIELD_NOT_SUPPORTED` |
| `limit` non-integer or below 1 | `400` | `INVALID_QUERY_PARAMETER` |
| Cursor unparseable, or from another endpoint | `400` | `INVALID_CURSOR` |
| Cursor older than the tombstone horizon (feeds only) | `410` | `SYNC_CURSOR_EXPIRED` |

`400` means the client sent something unusable. `410` means the client is too far behind and must resync the scope from the beginning. Conflating the two either resyncs pointlessly or loses data.

### What paging guarantees

The two kinds of collection guarantee different things, and the difference matters when data changes while a client is walking a page at a time.

**Change feeds**, ordered by the scope's sync sequence:

- ordered within the scope;
- at-least-once — a row may arrive twice, and none is skipped;
- replays are idempotent;
- `meta.sync_cursor` is always present and never null: it is the position after the page (or the incoming one when the page is empty), the value to store and send back as `cursor`. The two feeds are `GET /sync/user/changes` and `GET /sync/wallets/{id}/changes`;
- `meta.sync_position` (v1.10) is the same position as an integer, always sent, never sent back: store it per followed scope and compare it only with that same scope's head (`change_seq` in `GET /sync/wallets/heads`). Never compare cursors, and never compare it with a record's `change_seq`;
- **no ordering across scopes.** A row on the user feed and a row on a wallet feed have no defined relative order. A client needing a cross-scope invariant resolves it locally.

**Which wallets moved (v1.10).** `GET /sync/wallets/heads` lists, for each wallet the caller actively belongs to, its `change_seq` (the scope head) and `purged_change_seq` (the purge horizon), keyset-paged by `wallet_id` with no filter and no sort. A refresh reads the user feed and the heads (two requests), then reads only the wallets that moved. For a followed wallet with stored position `p`:

| Heads entry | Action |
|---|---|
| `change_seq == p` | skip the wallet's feed |
| `change_seq > p` | read the feed from the stored cursor |
| `purged_change_seq > p` | the stored cursor is expired; read from no cursor |
| listed, no stored position | read from the beginning |
| not listed | not a removal signal; read the feed (404 = access gone) and follow the user feed |

A display-name change re-sends the person's live `wallet_member` rows on their wallets' feeds shortly after the save (a queued job): a new `change_seq`, the same `version`, the name as of the read.

**Ordinary collections**, keyset paged on the sort key plus `id`:

- a row whose sort key does not change during the walk appears exactly once;
- a row inserted, deleted or re-sorted mid-walk may appear twice or not at all;
- no totals and no backward paging in MVP. Totals are expensive and the client's lists are append-oriented.

## Money, dates and numerals

Three value shapes that recur everywhere. The full field-convention table is in [the glossary](glossary.md); this section fixes their form on the wire.

### Money

```json
{ "amount_minor": 12500, "currency": "EGP" }
```

An integer count of minor units plus an ISO 4217 alpha-3 uppercase currency. The `_minor` suffix is what stops `12500` being read as 12,500 EGP. Where a money field has a specific meaning it is named for it: `balance_minor`, `opening_balance_minor`, `limit_minor`.

The API **refuses rather than coerces**:

| Sent | Result |
|---|---|
| `"125.00"` | `AMOUNT_NOT_INTEGER` |
| `125.0` | `AMOUNT_NOT_INTEGER` |
| `12500.5` | `AMOUNT_NOT_INTEGER` |
| `"egp"`, `"EGP "` | `INVALID_CURRENCY` |

Coercion is the exact failure this rule exists to prevent: `"125.00"` read as `12500` once and as `125` the next time someone touches the parser. Integers are bounded to ±(2^53 − 1), so a JavaScript client on the website cannot lose precision silently. Liability balances are negative, and the client presents them as an amount owed.

### Calendar dates and instants

```json
{ "date": "2026-09-15", "created_at": "2026-09-15T08:30:00.123456Z" }
```

| Kind | Form | Named |
|---|---|---|
| Calendar date | `YYYY-MM-DD`, no time, no offset | `date`, `<name>_date` |
| Instant | UTC with `Z`, microsecond precision | `<name>_at` |

The naming rule is what makes this reviewable: you can tell from the field name alone which of the two a value must be. A calendar date that acquires a timezone reports the wrong month to somebody, which is why a `date` field carrying a time or offset is refused with `DATE_NOT_CALENDAR_DATE`, and an `_at` field without `Z` with `INSTANT_NOT_UTC`.

A user's timezone is applied only in read models and reports, never at write time. Two members of a shared wallet in different timezones see the same totals for the same month.

### Numerals

Numerals in all output are Western `0-9`, in every locale, including inside an Arabic sentence. Egyptian users expect Western digits in financial figures.

## Localisation

`Accept-Language` selects the language of the human-readable strings. `ar` and `en` are supported. When the header is absent, a signed-in request is answered in the person's profile language (spec 008); otherwise, and whenever the header asks for anything else, `en` is the default.

Every response carries `Content-Language` with the language **actually used**. That is what lets a client tell "Arabic" apart from "Arabic was asked for and English came back", which is otherwise invisible and surfaces as a bug report about mixed-language screens.

| Localised | Never localised |
|---|---|
| `detail` | `code` |
| any user-facing message member | `title` |
| | field names and enum values |
| | numerals, which stay Western (see above) |

When a message has no Arabic string yet, that response falls back to English and `Content-Language` reports `en`. The fallback is per response, not per string, so a screen is never half-translated.

The API never sends pre-formatted directional strings or embedded layout markup. Right-to-left rendering is the client's job, and the contract's only obligation is not to obstruct it.

## Retrying

Whether a failure is worth retrying is a property of the **code**, not of the status class. The catalogue gives every code a `retryable` value, and a client's retry policy is a lookup rather than a hand-maintained list.

| Value | What the client does | Examples |
|---|---|---|
| `automatic` | retry the identical request with backoff | `RATE_LIMITED`, `INTERNAL_ERROR`, `SERVICE_UNAVAILABLE`, `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
| `after-change` | retry only after changing something | `VERSION_CONFLICT` (merge, then resend against the new version), `SYNC_CURSOR_EXPIRED` (resync, new cursor), `VALIDATION_FAILED` (fix the input) |
| `never` | user or app action required; resending is pointless | `UNAUTHENTICATED`, `NOT_FOUND`, `FORBIDDEN`, `CLIENT_VERSION_UNSUPPORTED`, `IDEMPOTENCY_KEY_REUSED` |

Status class is the wrong signal, and two codes make that concrete: `VERSION_CONFLICT` and `IDEMPOTENCY_KEY_REUSED` are both `409`, and they sit in different buckets. One says "merge and try again", the other says "you already did this with different data — stop".

When a response carries `Retry-After`, honour it. It is in seconds, and it appears on `429`, on `503`, and on an idempotent request whose original is still in flight.

## Syncable resources

A syncable resource is one the offline client holds a copy of and reconciles through a change feed. Eleven of them exist, and every one exposes the same six members in addition to its own fields.

```json
{
  "id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70",
  "version": 4,
  "change_seq": 1287,
  "created_at": "2026-09-15T08:30:00.123456Z",
  "updated_at": "2026-09-15T09:02:11.004000Z",
  "deleted_at": null
}
```

| Member | What it is |
|---|---|
| `id` | UUIDv7, generated by the client so a record can be created with no network |
| `version` | server-incremented; the client echoes its last-seen value on every write |
| `change_seq` | sync ordering within the scope, assigned by the server in commit order and never accepted from a client |
| `created_at`, `updated_at` | UTC instants |
| `deleted_at` | null when live, a UTC instant when deleted — a tombstone, never a hard delete |

### Two scopes, and only two

Each resource also carries **exactly one** scope key. There is no third scope, and no resource carries both keys or neither.

| Scope | Key | Resources |
|---|---|---|
| User | `user_id` | Profile, Device Session, Category, Budget |
| Wallet | `wallet_id` | Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence, Invitation |

Four of the eleven are user-scoped, so any envelope that assumes a wallet identifier is wrong for more than a third of the surface. A budget is personal rather than a household limit, and a category belongs to the person who made it. That is also why a shared wallet needs [Category Reference](#read-only-resources): so one member can render a transaction their partner categorised, without gaining any right to edit their partner's categories.

A client tracks one cursor per scope and cannot order events between scopes. A membership grant arrives on the user feed while the first transaction in that wallet arrives on the wallet feed, and the two have no defined relative order.

### What is not a syncable resource

Postings are never exposed — not under this name, not under another, not in a feed, not in a read. A transaction's effect on balances is internal to the server. A client that could see postings could construct a set that does not sum to zero, which is the one thing the ledger cannot allow.

## Writes

Creating a syncable record is **not** a `POST`. The client generates the identifier, so a create is an idempotent upsert addressed by that identifier:

```http
PUT /api/v1/transactions/0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70
```

```json
{ "amount_minor": -12500, "currency": "EGP", "date": "2026-09-15", "version": 4 }
```

| Member | Rule |
|---|---|
| `version` | the client's last-seen value. Omitted or `0` means create |
| `id` | comes from the path, not the body |
| `change_seq`, `created_at`, `updated_at` | server-owned. Sending one is `UNKNOWN_FIELD` |
| `deleted_at` | sent only to delete (an instant) or to restore (`null`) |

This is what makes a retry safe. The client cannot know whether a request that timed out was applied, so the contract makes applying it twice indistinguishable from applying it once.

### Batch upload

`POST /api/v1/sync/batch` carries 1 to 50 per-record writes in one request ([ADR-0016](adr/0016-batch-upload-beside-per-record-writes.md)). It is a transport, not a new kind of write: each item is exactly one per-record write (`PUT`, `DELETE`, or the restoring `PATCH`) and obeys exactly its rules. Items are independent, applied one at a time in array order, and each is answered by its own result (`applied`, `conflict` or `rejected`, with the status and body the single write gives). An unusable envelope is refused whole with `422` and nothing is applied. The per-record writes stay available and unchanged beside it. The item types are `wallet`, `category`, `transaction`, `profile`, `recurring_rule`, `budget` (`upsert`, `delete`) and `occurrence` (`upsert` only: an occurrence is confirmed, skipped or edited, never created or deleted by a client). **Invitations are not batch items** (spec 016): creating one sends a mail or returns a one-time link, so it goes through `PUT /wallets/{id}/invitations/{iid}` alone.

### Every outcome

| Case | Status | Body |
|---|---|---|
| Created | `201` | the resource |
| Updated | `200` | the resource |
| Identical replay of a write already applied | `200` | the resource, with `version` and `change_seq` unchanged |
| Stale `version` | `409 VERSION_CONFLICT` | problem, with the record's present state in `current` |
| Deleted by another member, then edited | `409 VERSION_CONFLICT` | problem, with `current` carrying the tombstone |
| `id` exists in another scope, or belongs to another entity type | `404 NOT_FOUND` | problem, disclosing nothing |
| Caller is not a member of the target wallet | `404 NOT_FOUND` | problem, disclosing nothing |
| `id` is not a UUIDv7 | `422 INVALID_ID` | problem |
| The resource is read-only | `405 METHOD_NOT_ALLOWED` | problem, with `Allow` |

A replay is deliberately indistinguishable from an update: both are `200` with the current record. The client does not need to tell them apart, and making them distinguishable would mean the server remembering every write forever.

`404` rather than `403` for a foreign identifier is not politeness. A `403` would confirm that the record exists, which tells a caller something about another user's data.

### What a `POST` is for

`POST` is reserved for operations that are not resource creation: submitting a claim, redeeming a code, acknowledging a purchase, authenticating. Those are the operations that carry an [idempotency key](#idempotency), because they have no client-generated identifier to be idempotent by.

## Version conflicts

Two people share a wallet. Both are offline. Both edit the same transaction. Exactly one of those writes lands first, and the second one is refused.

```json
{
  "type": "https://api.example/problems/version-conflict",
  "title": "Version conflict",
  "status": 409,
  "detail": "This record changed on another device.",
  "code": "VERSION_CONFLICT",
  "request_id": "0192f7a0-…",
  "current": {
    "id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70",
    "wallet_id": "0192f79e-…",
    "amount_minor": -14000,
    "currency": "EGP",
    "version": 6,
    "change_seq": 1301,
    "created_at": "2026-09-15T08:30:00.123456Z",
    "updated_at": "2026-09-16T11:40:02.900000Z",
    "deleted_at": null
  }
}
```

Four rules, and they hold for every endpoint:

1. **The whole write is refused.** Nothing is applied — not the fields that did not conflict, not a partial update.
2. **`current` carries the record's present state**, under the same schema a read returns. The client merges and resends against the new `version` without a second request.
3. **The server never merges.** A field-level merge produces a row neither person wrote — the amount from one device, the note from the other — and neither is ever shown that it happened. The client owns the merge because the client knows what the user typed and can show it to them.
4. **A tombstone is a current state like any other.** If the other member deleted the record, `current` comes back with `deleted_at` set. The edit is refused, not silently turned into a resurrection.

### Deleting and restoring

Deletion is a write that sets `deleted_at`, bumps `version` and bumps `change_seq`. The row survives, and the tombstone travels through the change feed so every other device learns about it.

Restoring is an explicit write of `deleted_at: null` carrying the current `version`, authorised exactly like any other update. An ordinary edit to a tombstoned record is **not** an implicit undelete — it is a conflict, and the client decides whether the user meant to bring the record back.

Tombstones are retained for at least 90 days. A client offline longer than that gets `410 SYNC_CURSOR_EXPIRED` on its next pull and resyncs the scope from scratch. Precisely: a daily purge removes older tombstones and raises the scope's purge horizon, and a cursor behind that horizon is the one that expires; a cursor at or beyond it is served, however old.

## Idempotency

Most writes need no idempotency header: a syncable create is already idempotent by its client-generated identifier. Three operations are not, because they have no such identifier and they move money:

- InstaPay claim submission
- Redemption-code redemption
- Google Play purchase acknowledgement

Those three **require** `Idempotency-Key`. Nothing else uses it — a header nobody sends is a header nobody can rely on.

The sync batch carries no key: it is idempotent because every item is, by its identifier and `version`. A key sent with it is ignored and nothing is stored.

### The key

An opaque client string, 16 to 255 characters from `[A-Za-z0-9._:-]`. The server stores it for **24 hours**, scoped to the authenticated user, against a fingerprint of the method, the path and the canonical request body, together with the status and body it produced.

| Situation | Outcome |
|---|---|
| Key absent on an operation that requires it | `400 IDEMPOTENCY_KEY_REQUIRED` |
| Key malformed | `400 IDEMPOTENCY_KEY_INVALID` |
| Same key, same fingerprint, original completed | the stored response, replayed verbatim, with `Idempotency-Replayed: true` |
| Same key, **different** payload | `409 IDEMPOTENCY_KEY_REUSED` |
| Same key, original still in flight | `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` with `Retry-After` |

Two details carry the weight here.

**The response is replayed, not recomputed.** A retry returns the *same* answer, not merely a second correct one. Recomputing could produce a different subscription expiry or a different claim reference for what the user thinks was one action.

**A different payload is a conflict, never a new request.** Silently treating it as new is how a client bug becomes a double charge. The refusal is its own code so a client can tell it apart from a version conflict, which is also a `409` but means the opposite thing — retry after merging, rather than stop.

## Read-only resources

Some resources are produced by the server and never accepted from a client. Today there is one: **Category Reference**, the projection that lets a member render a transaction their partner categorised, without seeing or editing their partner's category list.

A read-only resource appears in reads and in its change feed like anything else. It has **no write operation at all** — not a forbidden one, not a hidden one. A write to its path is:

```http
405 Method Not Allowed
Allow: GET
```

with the ordinary problem body and `code: METHOD_NOT_ALLOWED`.

`405` is the honest answer: the path exists, the method does not. The two alternatives are both wrong.

- `403` would imply a permission the caller could conceivably be granted. Nobody can write this resource, ever, including its own owner.
- `404` would contradict the record the client just read out of a feed.

### A server-created record that accepts a write

An **Occurrence** (recurring transactions, [ADR-0018](adr/0018-occurrences-materialised-by-the-server.md)) is the second server-produced syncable record, and unlike a Category Reference it has a write: a `PUT` of its writable members with the last-seen `version`, to confirm, skip or edit it. That `PUT` never creates. An id the server did not mint is `404 NOT_FOUND`, never `201`, and there is no `DELETE`: it goes with its rule. The rule that "a create is a `PUT` with a client id" holds for every record a client creates; the occurrence is not one.

## Client version gating

Every request identifies its caller:

```http
X-Client-Platform: android
X-Client-Version: 1.4.2
```

Both are **required**, on every endpoint. An optional header is one nobody sends, and one nobody can rely on when the time comes to use it.

The server holds a minimum supported version per platform.

| Situation | Status | Code |
|---|---|---|
| Version below the floor | `426` | `CLIENT_VERSION_UNSUPPORTED` |
| Either header absent | `400` | `CLIENT_VERSION_REQUIRED` |
| Version not semver, or platform unknown | `400` | `CLIENT_VERSION_INVALID` |

The `426` body carries `minimum_version`:

```json
{
  "type": "https://api.example/problems/client-version-unsupported",
  "title": "Client version unsupported",
  "status": 426,
  "detail": "Update the app to continue.",
  "code": "CLIENT_VERSION_UNSUPPORTED",
  "request_id": "0192f7a0-…",
  "minimum_version": "1.6.0"
}
```

That is the point of a distinct code and a distinct status: the app maps it to an update prompt naming the version, instead of showing a user a generic failure they cannot act on.

**The gate applies to reads as well as writes.** An outdated client can misread a payload as easily as it can write a bad one, and a client too old to understand a money field is not made safer by being allowed to display it.

This machinery exists from the first release even though nothing is below the floor yet. A header introduced later cannot be relied on by the clients already in the field, which is exactly when it would be needed.

## Rate limiting

Every response from a throttled route reports the caller's standing, so a sync loop backs off from headers rather than guesswork:

```http
RateLimit-Limit: 120
RateLimit-Remaining: 7
RateLimit-Reset: 43
```

`RateLimit-Reset` is a delta in **seconds until the window resets**, not a timestamp — a client with a wrong clock still computes the right wait.

A refusal is `429` with `code: RATE_LIMITED` and a `Retry-After` header, also in seconds. `RATE_LIMITED` is `automatic` in the retry table: resend the identical request after the stated wait.

Per-route budgets — how many requests, over what window, per user or per address — belong to the security baseline, not here. This document fixes only the header names and the shape of the refusal, so a client written today keeps working when the budgets are set.

**Implementation note.** Laravel's throttle middleware emits `X-RateLimit-Limit` and `X-RateLimit-Remaining` by default. The names above are the ones this contract promises, so the middleware's headers have to be overridden rather than accepted. It is written down here because the alternative is discovering it as a client bug.

## Deprecation and removal

An endpoint being retired keeps working, and says so on every response:

```http
Deprecation: @1774915200
Sunset: Sat, 31 Oct 2026 00:00:00 GMT
Link: <https://api.example/problems/endpoint-deprecated>; rel="deprecation"
```

| Header | Standard | Answers |
|---|---|---|
| `Deprecation` | RFC 9745 structured date | is this dying? |
| `Sunset` | RFC 8594 HTTP-date | exactly when does it stop? |
| `Link` with `rel="deprecation"` | RFC 8288 | what replaces it? |

Two headers rather than one because they answer different questions. A client that only wants to warn its own developers reads the first; a client that wants to hard-block before a user loses data reads the second.

Until the sunset instant the endpoint **succeeds normally**. After it, the endpoint returns `410` with `code: ENDPOINT_REMOVED`.

Deprecation is advertised for at least one full client release cycle before the sunset date. A client that cannot ship in time is the reason the date exists; moving it is cheaper than stranding users.

Retiring an endpoint this way does not bump `v1`. The version number moves only for a breaking change to the surface as a whole — see [Base path and versioning](#base-path-and-versioning).

> **Where credentials come from.** This document fixes the bearer scheme and the refusals around it. How a credential is issued, how long it lives, how it is revoked, and what a caller must have verified before it works are in [docs/security-baseline.md](security-baseline.md).

## Extending the skeleton

A feature spec describes only what is unique to its endpoints. The envelope, the error catalogue, paging, auth, the headers and the shared schemas already exist, and the feature references them.

### What a feature adds

**One file**, `docs/openapi/paths/<feature>.yaml`, containing its operations and its own resource schemas. Optionally **one or more entries** in `error-catalog.yaml`, when it introduces a genuinely new error condition. Optionally **one line** in `openapi.yaml`'s paths index, and one tag if it opens a new bounded context.

Start from [`docs/openapi/paths/_template.yaml`](openapi/paths/_template.yaml), which shows a collection operation and an upsert operation with everything shared referenced rather than restated.

### What a feature must not do

- redefine `Envelope`, `Problem`, `ValidationError`, `SyncableResource` or any shared parameter;
- write a problem response inline instead of referencing `components/responses.yaml`;
- use an error string that is not in the catalogue;
- add a second success envelope shape;
- change `openapi.yaml` beyond its paths entry and, if needed, a tag.

If a feature genuinely cannot express itself with the shared components, that is a gap in the components, not a reason for a local shape. Raise it and fix it in `components/`, where every other feature gets the fix too.

### What every operation declares

| Element | Requirement |
|---|---|
| `operationId` | unique, camelCase, verb first |
| `tags` | exactly one, an existing bounded-context tag |
| `summary` | one line |
| responses | every success it can return, and every problem response it can return |
| `security` | inherited from the root; a public operation states `security: []` explicitly |
| `x-filterable` / `x-sortable` | on every collection operation, even when the list is empty |

An operation that omits a problem response it can actually return is incomplete, not concise. A client that has not been told about a `409` will not handle it.

### Before you call it done

Run the [endpoint review checklist](#appendix-endpoint-review-checklist) against each operation. Every line is answerable yes or no by looking at that one operation.

## Source of truth

[`docs/openapi/`](openapi/openapi.yaml) is the source of truth for this contract. This document explains it.

Where the two disagree, the OpenAPI files win, and the disagreement is a **defect to fix in the same change** — not a note to leave for later, and not a judgement call for whoever reads it next.

Two consequences worth stating outright.

**The error catalogue is never copied here.** It lives in [`error-catalog.yaml`](openapi/error-catalog.yaml) and this document links to it. A forty-row table transcribed into two places drifts within a month, and the copy that drifts is always the readable one people trust.

**A change to the contract is a change to the YAML.** Editing only this document produces a contract nobody's tooling can see. Edit the skeleton, then describe it here, in one commit.

## Appendix: endpoint review checklist

Run this against **one** operation at a time. Every line is answerable yes or no by looking at that operation alone, which is what makes the conventions above reviewable rather than aspirational.

A reviewer who answers "no" to any line either fixes the endpoint or records why the exception is correct in the feature's plan. A "no" with no record is a defect.
FR-034 requires every convention in this feature to be expressible as a check a reviewer applies to **one** endpoint definition. This is that list. A reviewer runs it against a single operation in `docs/openapi/paths/<feature>.yaml` plus its request and response examples. Every line is answerable yes or no without reading another endpoint.

### A. Shape

- [ ] A1 — Path begins `/api/v1/` and contains no version segment of its own.
- [ ] A2 — Success responses use `application/json`; problem responses use `application/problem+json`.
- [ ] A3 — Every success body is `{ "data": …, "meta": … }`, with `meta` present even when empty.
- [ ] A4 — No second envelope shape, no bare resource at the top level.
- [ ] A5 — `204` is used only where there is genuinely no body; an empty list is `200` with `data: []`.

### B. Errors

- [ ] B1 — Every problem response `$ref`s `components/responses.yaml`; none is defined inline.
- [ ] B2 — Every error code the operation can return exists in `error-catalog.yaml`.
- [ ] B3 — No new code was invented locally; a genuinely new condition added a catalogue entry in the same change.
- [ ] B4 — Each code the operation uses maps to the one status the catalogue gives it.
- [ ] B5 — A validation response documents `errors[]` with JSON Pointer `pointer` and a field-level `code`.
- [ ] B6 — Nothing in the operation asks the client to branch on `title` or `detail`.

### C. Identifiers and writes

- [ ] C1 — A create is `PUT /…/{id}` with a client-generated UUIDv7, not `POST` with a server-assigned id.
- [ ] C2 — `201` is documented for create, `200` for update and for replay.
- [ ] C3 — The request body carries `version`; `change_seq`, `created_at` and `updated_at` are not accepted.
- [ ] C4 — `409 VERSION_CONFLICT` is documented and its body carries `current`.
- [ ] C5 — The deleted-then-edited case returns `409` with a tombstoned `current`, not `404` and not a silent revival.
- [ ] C6 — An identifier outside the caller's scope returns `404`, never `403`, and discloses nothing.
- [ ] C7 — A non-UUIDv7 identifier returns `422 INVALID_ID`.
- [ ] C8 — A read-only resource has no write operation, and its path documents `405` with `Allow`.

### D. Syncable envelope

- [ ] D1 — The resource schema composes `SyncableResource` rather than repeating its members.
- [ ] D2 — It adds **exactly one** scope key: `wallet_id` or `user_id`, never both, never neither.
- [ ] D3 — The scope matches the glossary: Profile, Device Session, Category and Budget are user-scoped; the other seven are wallet-scoped.
- [ ] D4 — `deleted_at` is documented as nullable and tombstones appear in feeds.
- [ ] D5 — No posting is exposed, in any shape, under any name.

### E. Collections

- [ ] E1 — Paging is `cursor` + `limit`; there is no `page`, `offset` or `total`.
- [ ] E2 — `meta` documents `next_cursor`, `has_more` and `limit`.
- [ ] E3 — `x-filterable` and `x-sortable` are both present, even if empty.
- [ ] E4 — The filter grammar is `filter[field]=op:value`; no bespoke parameter names such as `date_from`.
- [ ] E5 — Unsupported field and operator refusals are documented.
- [ ] E6 — The cursor is documented as opaque; nothing in the operation implies a client can compute one.

### F. Values

- [ ] F1 — Every money value is `<name>_minor` integer plus `currency`; no decimal string, no float.
- [ ] F2 — Calendar dates are `format: date`; instants are `format: date-time` in UTC.
- [ ] F3 — A field named `<name>_at` is an instant; a field named `date` or `<name>_date` is a calendar date. Nothing is named against its type.
- [ ] F4 — Field names follow the glossary conventions; none of the banned variants appears (`amount_cents`, `is_deleted`, `rev`, `seq`, `space_id`, `updated_time`).
- [ ] F5 — No user-facing string is hardcoded English in the schema; localised members are marked as localised.

### G. Headers and request handling

- [ ] G1 — `X-Request-Id` is documented on every response, success and error.
- [ ] G2 — The operation neither requires nor invents a header outside the inventory in the header inventory below.
- [ ] G3 — `Idempotency-Key` is required if and only if the operation is one of the three non-upsert money operations.
- [ ] G4 — If it is one of them, `409 IDEMPOTENCY_KEY_REUSED` and `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` are documented.
- [ ] G5 — Throttled routes document `429` with `Retry-After` and the `RateLimit-*` headers.
- [ ] G6 — `426 CLIENT_VERSION_UNSUPPORTED` is reachable; the operation does not opt out of the version gate.

### H. Security

- [ ] H1 — The operation inherits the global bearer scheme, or states `security: []` deliberately.
- [ ] H2 — `401`, `403` and `404` follow the discipline: not authenticated / member but under-privileged / everything else.
- [ ] H3 — Entitlement denial is `403 FEATURE_NOT_ENTITLED`, distinct from role denial.
- [ ] H4 — Nothing in a response body or error message discloses the existence of a resource the caller cannot read.

### I. Documentation hygiene

- [ ] I1 — `operationId` is unique and verb-first; exactly one tag, from the context list.
- [ ] I2 — The operation adds nothing to `openapi.yaml` beyond one `paths` entry.
- [ ] I3 — Nothing in the operation restates a shared schema instead of `$ref`-ing it.
- [ ] I4 — `docs/api-conventions.md` needed no change; if it did, the change is in the same commit and does not contradict the skeleton.

### Using this list

A reviewer who answers "no" to any line either fixes the endpoint or records why the exception is correct in the feature's plan. A "no" with no record is a defect. The list is also the source for the reviewer exercise in the feature specs.
