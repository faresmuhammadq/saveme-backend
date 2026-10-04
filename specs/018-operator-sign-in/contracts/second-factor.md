# Contract: Second factor (codes, setup, recovery codes, re-confirmation)

## Time-based code (RFC 6238 over RFC 4226)

```text
K      = secret, 20 random bytes
T      = floor(unix_seconds / 30)
HS     = HMAC-SHA1(K, uint64_be(T))                      -- hash_hmac('sha1', pack('J', T), K, true)
offset = HS[19] & 0x0f
bin    = (HS[offset] & 0x7f) << 24 | HS[offset+1] << 16 | HS[offset+2] << 8 | HS[offset+3]
code   = str_pad(bin % 1_000_000, 6, '0', STR_PAD_LEFT)
```

`Totp::verify(K, input, now, lastStep): ?int`

1. `input` stripped of spaces; must match `^\d{6}$`, else no match (still a failure for the counter).
2. For each `s` in `[T-1, T, T+1]` compute `code(s)` and compare with `hash_equals`; evaluate all three, no early return.
3. Matched step `m` (the latest if several, impossible in practice) is accepted only if `lastStep === null || m > lastStep`.
4. Returns `m` or `null`. The caller writes `last_code_step = m` in the same transaction, under `FOR UPDATE` on the operator.

Test vectors (`tests/Unit/Admin/TotpTest.php`), seed `"12345678901234567890"` (ASCII), SHA-1, last six digits of RFC 6238 Appendix B:

| Unix time | Step (hex) | 8-digit RFC value | 6-digit code |
|---|---|---|---|
| 59 | `0000000000000001` | 94287082 | 287082 |
| 1111111109 | `00000000023523EC` | 07081804 | 081804 |
| 1111111111 | `00000000023523ED` | 14050471 | 050471 |
| 1234567890 | `000000000273EF07` | 89005924 | 005924 |
| 2000000000 | `0000000003F940AA` | 69279037 | 279037 |
| 20000000000 | `0000000027BC86AA` | 65353130 | 353130 |

RFC 4226 Appendix D HOTP counters 0–9 (`755224, 287082, 359152, 969429, 338314, 254676, 287922, 162583, 399871, 520489`) are also asserted.

Drift and replay cases: code of `T-1` and `T+1` accepted; `T-2`, `T+2` refused; the same step twice refused; an earlier step after a later one refused.

## Setup key and link

- Key: `Base32::encode(K)` → 32 characters, shown as `ABCD EFGH …` (eight groups of four).
- Link: `otpauth://totp/{rawurlencode(issuer)}:{rawurlencode(email)}?secret={key}&issuer={rawurlencode(issuer)}&algorithm=SHA1&digits=6&period=30`, issuer from `config('admin.auth.issuer')`.
- The pending secret is sealed into `pending_secret_sealed` when the setup page first renders, reused on reload for 15 minutes, then replaced. On a correct code it moves to `secret_sealed` (and `last_code_step` = the matched step, so the confirming code cannot be replayed at sign-in).

## Recovery codes

- Alphabet `0123456789ABCDEFGHJKMNPQRSTVWXYZ`, 10 characters from `random_int`, displayed `XXXXX-XXXXX`.
- Input normalisation: upper-case; remove spaces, `-`; map `I`,`L` → `1`, `O` → `0`; must then be 10 characters of the alphabet.
- Stored: `hash_hmac('sha256', normalised, ADMIN_CODE_KEY)`. Verification: all unused hashes of the operator compared with `hash_equals`, under the operator row lock; a match sets `used_at = now`.
- Issue: delete every existing row of the operator, insert ten with a new `set_id`, in one transaction; return the plain codes to the caller only.

## Verifier service

`SecondFactorVerifier::verify(OperatorId, string $input, Purpose $purpose): VerifiedFactor` where `Purpose ∈ {sign_in, reconfirm}`; `$input` is tried as a six-digit code when it is six digits, otherwise as a recovery code. On failure it records `sign_in_failed` (stage `second_factor` or `reconfirm`), hits the account and origin counters (and `lockout_applied` when a lock begins), and throws `SecondFactorInvalid` or `OperatorLocked {retryAfterSeconds}`. On success it returns `{kind: code|recovery_code, codesLeft}`.

## Re-confirmation trait

```php
// In a BackOfficeComponent:
use RequiresRecentFactor;

public function regenerate(): void
{
    $this->requireRecentFactor('regenerateCodes');
}

protected function regenerateCodes(): void { … }
```

- Public state (all `#[Locked]`): `factorMethod`, `factorArguments` (scalars), `factorOpen`.
- `requireRecentFactor(method, args)`: if `session('admin.factor_confirmed_at')` ≥ now − 10 min, call the protected method; else store the pending call and open `<x-admin::factor-prompt>`.
- `confirmFactor(string $oneTimeCode)`: verifier with purpose `reconfirm`; on success set `admin.factor_confirmed_at = now` and run the pending protected method; on failure add the error to `one_time_code`.
- Composes with `RequiresReason`: the protected method of the factor step calls `requireReason(...)`.
- Full sign-in (code or recovery code) sets `admin.factor_confirmed_at`.
