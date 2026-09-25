# Contract: Protected Attributes

Normative. The table that replaces "everything is encrypted" with an answer per attribute. It goes into `docs/security-baseline.md` verbatim, and `docs/privacy-commitments.md` may not claim anything this table does not support.

## The table

| Attribute | Protection at rest | Can it be searched? | Who can read it |
|---|---|---|---|
| Address | encrypted, with a keyed lookup value beside it | by lookup value only | the account owner, and an operator with a recorded read |
| Display name | encrypted | no | the account owner, other members of a shared wallet, an operator with a recorded read |
| Password | argon2id hash | no | nobody, ever, at any level of privilege |
| Session credential | hash | by hash | nobody after issue |
| Reset and verification tokens | hash | by hash | nobody after issue |
| Amount, balance | none at rest | yes | members of the wallet |
| Note | none at rest | yes | members of the wallet |
| Wallet and category names | none at rest | yes | members of the wallet, and the category's owner |
| Audit entries | none at rest, and they carry nothing protected | yes | operators only, and the read is recorded |

## Rules

1. An attribute not in this table has **no** protection claim attached to it. Adding a claim means adding a row.
2. Amounts and notes are deliberately unencrypted at rest. Arithmetic, aggregation and indexing depend on them, and an attacker who reaches the database also reaches the application that would decrypt them. They are protected by scope, transport and access control instead. The commitments say this rather than implying otherwise.
3. Encryption is authenticated, with a per-record nonce and the key identifier stored beside the value.
4. A hash is one-way. A row marked "hash" is never returned by any endpoint, to any caller.
5. Anything marked "an operator with a recorded read" means the read produces an `audit_read` entry.

## The keyed lookup value

```text
lookup = HMAC-SHA256(normalise(address), lookup_key)
```

Normalisation, identical everywhere the value is produced:

1. trim surrounding whitespace
2. Unicode NFKC
3. lowercase the entire address

Dots and plus-tags are **not** stripped. Two addresses differing by them are different addresses to anyone who is not that provider, and merging them silently merges two people.

**Rules**

- Unique-indexed. Sign-in, "is this address registered" and invitation matching all use it and never a readable address.
- The lookup key is separate from the encryption key and lives outside the database.
- The limit is stated, not hidden: this defeats an attacker holding the stored data. It does not defeat one holding the data **and** the lookup key, who can confirm an address they already guessed, though not enumerate the addresses that exist.

## Key rotation

- Every encrypted value stores the identifier of the key that produced it, so a partially rotated table is a valid state rather than a corrupt one.
- Rotating an encryption key: add the new key, write new values with it, keep the old key readable until a re-encryption pass finishes. Nobody re-registers. No value is unreadable mid-rotation.
- Rotating the **lookup** key is harder, because the value is an index. The sequence is: write the new value into a second column, backfill, swap the unique index, drop the old column. The baseline states this so it is a planned operation and not an outage.
- Keys live in the deployment's secret store, never in the database they protect.

## Reviewer checks

- [ ] Every attribute a feature stores appears in this table or is added to it in the same change.
- [ ] No document, page or store listing claims protection this table does not grant.
- [ ] No endpoint returns a value whose row says "hash".
- [ ] Every lookup by address goes through the keyed value.
