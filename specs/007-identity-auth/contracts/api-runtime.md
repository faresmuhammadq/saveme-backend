# Contract: API runtime (first implementation of spec 003)

What every `/api/v1` response gets from the shared runtime (research R-15). The behaviour is fixed by `docs/api-conventions.md` and `docs/openapi/components/`. This lists what B1 implements, and the test that proves each piece.

| Behaviour | Implemented by | Proven by |
|---|---|---|
| `{data, meta}` envelope, with an empty `meta` serialised as `{}` | `App\Http\Api\Envelope` | `tests/Contract/Api/EnvelopeTest.php` |
| Problem body (`type`, `title` from the catalogue, localised `detail`, `status`, `code`, `request_id`, `errors[]` with `pointer` and `code`) as `application/problem+json` | `App\Http\Api\Problems\ProblemRenderer` | `tests/Contract/Api/ProblemTest.php` |
| Every catalogue code maps to exactly its catalogue status | `Shared\Domain\Errors\ErrorCode::status()` | `tests/Contract/OpenApi/ErrorCodeParityTest.php` (enum ⇄ `error-catalog.yaml`) |
| `X-Request-Id` (UUIDv7) on every response; `X-Correlation-Id` validated, echoed and propagated to the log context and queued jobs | `AssignRequestId` | `tests/Contract/Api/RequestIdTest.php` |
| Plain HTTP refused with `400 INSECURE_TRANSPORT`; `Strict-Transport-Security: max-age=31536000` | `RefuseInsecureTransport` (honours trusted proxies; disabled only when `APP_ENV=local`) | `tests/Contract/Api/TransportTest.php` |
| `406 NOT_ACCEPTABLE`, `415 UNSUPPORTED_MEDIA_TYPE`, `400 MALFORMED_JSON` | `NegotiateJson` and the renderer | `tests/Contract/Api/ContentNegotiationTest.php` |
| `X-Client-Platform` and `X-Client-Version` required: `400 CLIENT_VERSION_REQUIRED` / `CLIENT_VERSION_INVALID`, `426 CLIENT_VERSION_UNSUPPORTED` | `RequireClientVersion` | `tests/Contract/Api/ClientVersionTest.php` |
| `Content-Language` is the language actually used; `Accept-Language` resolved to `ar` or `en` | `ResolveContentLanguage` | `tests/Contract/Api/LocalisationTest.php` |
| `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` (in seconds), never `X-RateLimit-*`; `429 RATE_LIMITED` with `Retry-After` | `StandardRateLimitHeaders` | `tests/Contract/Api/RateLimitHeadersTest.php` |
| An unknown route under `/api/v1` gives `404 NOT_FOUND`; a wrong method gives `405 METHOD_NOT_ALLOWED` with `Allow` | the renderer | `tests/Contract/Api/ProblemTest.php` |
| No password, credential, secret or `Authorization` value is logged, at any level | `RedactSecrets` Monolog processor | `tests/Feature/Support/LogRedactionTest.php` |

`detail` strings live in `lang/{en,ar}/problems.php`, one key per code. A completeness test checks both languages.

**Order in the `api` group**: `AssignRequestId` → `RefuseInsecureTransport` → `NegotiateJson` → `RequireClientVersion` → `ResolveContentLanguage` → `StandardRateLimitHeaders` → `SubstituteBindings`. The request id comes first, so even a transport refusal carries it.
