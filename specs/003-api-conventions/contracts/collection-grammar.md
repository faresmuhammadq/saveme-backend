# Contract: Collection Grammar

Normative. Applies to every collection endpoint, including change feeds.

## Query parameters

| Parameter | Form | Default | Bounds |
|---|---|---|---|
| `cursor` | opaque string | absent = from the beginning | produced by the same endpoint |
| `limit` | integer | `50` | 1–200; above 200 clamped |
| `filter[<field>]` | `<op>:<value>` or bare `<value>` | none | field must be in `x-filterable` |
| `sort` | comma list, `-` = descending | per endpoint | fields must be in `x-sortable` |

## Operators

`eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in`.

- A bare value means `eq`: `filter[wallet_id]=0192…` ≡ `filter[wallet_id]=eq:0192…`.
- `in` takes a comma-separated list: `filter[status]=in:pending,active`.
- Repeating a field ANDs its conditions: `filter[date]=gte:2026-01-01&filter[date]=lte:2026-01-31`.
- There is no `or`, no grouping, no nesting and no free-text query language.
- Values follow the field's own type rules: a date filter takes `YYYY-MM-DD`, a money filter takes minor units.

## Sorting

- `sort=-date,id` — descending by `date`, then ascending by `id`.
- Every sort is stabilised: the server appends `id` when the client has not, so the keyset cursor is total.
- An endpoint declares a default sort; omitting `sort` uses it.

## Declaring what is allowed

Every collection operation carries, in its OpenAPI operation object:

```yaml
x-filterable: [date, category_id, amount_minor]
x-sortable:   [date, amount_minor, created_at]
```

Both keys are required even when the list is empty. Anything outside the list is refused, never ignored.

## Refusals

| Situation | Status | Code |
|---|---|---|
| Field not in `x-filterable` | 400 | `FILTER_FIELD_NOT_SUPPORTED` |
| Operator not allowed for that field | 400 | `FILTER_OPERATOR_NOT_SUPPORTED` |
| Value not parseable as the field's type | 400 | `INVALID_QUERY_PARAMETER` |
| Field not in `x-sortable` | 400 | `SORT_FIELD_NOT_SUPPORTED` |
| `limit` non-integer or below 1 | 400 | `INVALID_QUERY_PARAMETER` |
| `cursor` unparseable or from another endpoint | 400 | `INVALID_CURSOR` |
| `cursor` older than the tombstone horizon (feeds only) | 410 | `SYNC_CURSOR_EXPIRED` |

A `limit` above 200 is **clamped, not refused**, and `meta.limit` reports the applied value.

## Guarantees the client may rely on

**Change feeds** (ordered by `change_seq` within one scope):

- ordered within the scope;
- at-least-once — a row may arrive twice, none is skipped;
- replays are idempotent;
- **no ordering across scopes.** A user-feed row and a wallet-feed row have no defined relative order.

**Ordinary collections** (keyset on the sort key plus `id`):

- a row whose sort key does not change during the walk appears exactly once;
- a row inserted, deleted or re-sorted mid-walk may appear twice or not at all;
- no totals, no backward paging in MVP.

## Cursor rules

The client may store a cursor and send it back. The client MUST NOT parse, compare, increment, construct or reuse one across endpoints. A cursor is valid only for the endpoint that issued it, and only with the same `filter` and `sort` — changing either invalidates it and the client starts a new walk.

## Last page

`meta.has_more` is `false` and `meta.next_cursor` is `null`, together, always. A client may test either; it must not have to test both.
