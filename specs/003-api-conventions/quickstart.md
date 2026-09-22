# Quickstart: Validating the API Conventions

**Feature**: `003-api-conventions` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

This feature ships two documents and no application code, so validation is mechanical checks over the files plus four human exercises that map one-to-one onto the success criteria. Run everything from the repository root.

## Prerequisites

- The deliverables exist: `docs/api-conventions.md`, `docs/openapi/openapi.yaml`, `docs/openapi/error-catalog.yaml`, `docs/openapi/components/*.yaml`.
- `symfony/yaml` is already installed as a transitive dependency, so the YAML checks need nothing new. `python3` with PyYAML works equally well if you prefer it.
- No database, no server and no `dev project open` is required. Nothing here boots the application.

---

## 1. Structural checks

### 1.1 Every YAML file parses

```bash
php -r 'require "vendor/autoload.php";
  $files = array_merge(glob("docs/openapi/*.yaml"), glob("docs/openapi/components/*.yaml"), glob("docs/openapi/paths/*.yaml"));
  foreach ($files as $f) { Symfony\Component\Yaml\Yaml::parseFile($f); echo "ok  $f\n"; }'
```

**Expected**: one `ok` line per file, no exception.

### 1.2 Every `$ref` resolves

```bash
php -r 'require "vendor/autoload.php";
  use Symfony\Component\Yaml\Yaml;
  $bad = 0;
  foreach (array_merge(glob("docs/openapi/*.yaml"), glob("docs/openapi/components/*.yaml"), glob("docs/openapi/paths/*.yaml")) as $f) {
    foreach (explode("\n", file_get_contents($f)) as $n => $line) {
      if (preg_match("~^\s*#~", $line)) { continue; }   // a YAML comment, not a ref
      if (!preg_match("~\\\$ref:\s*[\x27\"]?([^\x27\"\s]+)~", $line, $m)) { continue; }
      [$path, $frag] = array_pad(explode("#", $m[1], 2), 2, "");
      $target = $path === "" ? $f : dirname($f)."/".$path;
      if (!file_exists($target)) { echo "MISSING FILE  $f:".($n+1)."  {$m[1]}\n"; $bad++; continue; }
      $doc = Yaml::parseFile($target); $cur = $doc;
      foreach (array_filter(explode("/", $frag)) as $seg) {
        $seg = str_replace(["~1","~0"], ["/","~"], $seg);
        if (!is_array($cur) || !array_key_exists($seg, $cur)) { echo "MISSING KEY   $f:".($n+1)."  {$m[1]}\n"; $bad++; continue 2; }
        $cur = $cur[$seg];
      }
    }
  }
  echo $bad === 0 ? "all refs resolve\n" : "$bad broken refs\n";'
```

**Expected**: `all refs resolve`.

### 1.3 Catalogue and enum agree

```bash
php -r 'require "vendor/autoload.php";
  $d = Symfony\Component\Yaml\Yaml::parseFile("docs/openapi/error-catalog.yaml");
  $enum = array_merge($d["components"]["schemas"]["ErrorCode"]["enum"], $d["components"]["schemas"]["FieldErrorCode"]["enum"]);
  $cat  = array_keys($d["x-error-catalog"]);
  $a = array_diff($enum, $cat); $b = array_diff($cat, $enum);
  echo $a ? "in enum, not catalogued: ".implode(", ", $a)."\n" : "";
  echo $b ? "catalogued, not in enum: ".implode(", ", $b)."\n" : "";
  echo (!$a && !$b) ? "catalogue and enum agree (".count($cat)." codes)\n" : "";'
```

**Expected**: `catalogue and enum agree (38 codes)` — 28 response-level plus 11 field-level, less one because `INVALID_ID` is valid at both levels and carries a single entry.

### 1.4 Every code in the catalogue carries every required field

```bash
php -r 'require "vendor/autoload.php";
  $d = Symfony\Component\Yaml\Yaml::parseFile("docs/openapi/error-catalog.yaml");
  $need = ["status","level","title","meaning","retryable","since"]; $bad = 0;
  foreach ($d["x-error-catalog"] as $code => $e) {
    foreach ($need as $k) { if (!isset($e[$k])) { echo "$code missing $k\n"; $bad++; } }
    if (isset($e["retryable"]) && !in_array($e["retryable"], ["automatic","after-change","never"], true)) { echo "$code bad retryable\n"; $bad++; }
  }
  echo $bad === 0 ? "catalogue complete\n" : "$bad problems\n";'
```

**Expected**: `catalogue complete`.

### 1.5 Banned shapes appear nowhere

```bash
grep -rniE 'amount_cents|is_deleted|"rev"|space_id|updated_time|"offset"|"page"|total_count' \
  docs/openapi docs/api-conventions.md | grep -v 'banned variants'
```

**Expected**: no matches. A match is a contract violation, not a style preference. The `grep -v` drops the one legitimate mention: checklist item F4, which lists the banned variants in order to forbid them.

### 1.6 The superseded contract points forward

```bash
head -5 specs/001-architecture-constitution-adrs/contracts/http-conventions.md
```

**Expected**: a superseded banner naming `docs/api-conventions.md` and `docs/openapi/`.

---

## 2. Reviewer exercise — SC-005

Give a reviewer the conventions, the checklist in [contracts/endpoint-review-checklist.md](./contracts/endpoint-review-checklist.md), and this seeded endpoint definition. Ask which convention each line breaks.

```yaml
# seeded violations — do not fix, this is the exercise
post:
  operationId: createTransaction
  parameters:
    - { name: page, in: query, schema: { type: integer } }
  requestBody:
    content:
      application/json:
        schema:
          properties:
            amount: { type: string, example: "125.00" }
            date:   { type: string, format: date-time }
  responses:
    "201": { description: created }
    "400":
      content:
        application/json:
          example: { error: "TXN_BAD_INPUT", message: "bad amount" }
```

**Expected**: 5 of 5 identified.

| # | Violation | Convention |
|---|---|---|
| 1 | `amount` as a decimal string | money is `<name>_minor` integer + `currency` (F1, R-16) |
| 2 | `date` as `date-time` | calendar dates are date-only (F2/F3, R-17) |
| 3 | `TXN_BAD_INPUT` invented locally | one catalogue, no local strings (B2/B3, R-6) |
| 4 | `POST` create with a server-assigned id | create is `PUT /…/{id}` (C1, R-9) |
| 5 | `page` parameter, no cursor | cursor paging only (E1, R-12) |

Bonus findings a good reviewer also raises: the error body is not RFC 9457, its media type is `application/json`, there is no `meta`, and `X-Request-Id` is undocumented.

---

## 3. Client-developer exercise — SC-001

Hand someone who has not read this spec only `docs/api-conventions.md` and one feature's endpoint list. Ask them to write, on paper, the client types and flow for a list endpoint and a write endpoint.

**Expected**: they produce, with **zero** clarifying questions, all of:

- the generic `Envelope<T>` with `data` and `meta`;
- the paging loop, including how they know it is the last page;
- the problem type with `code` as the branch, not the message;
- the validation path mapping `pointer` to an input;
- the `409` handler that reads `current` and merges without a second request;
- the headers they must send on every request.

A question about any of those six is a gap in the document, recorded as a defect against it.

---

## 4. Offline-write exercise — SC-004

For each of the five cases, the reviewer states the status, the code and the body **from the document alone**, with no "it depends on the endpoint".

| Case | Expected |
|---|---|
| Create with a client id | `201` + resource |
| Same write retried | `200` + unchanged resource, no `version` or `change_seq` bump |
| Stale `version` | `409 VERSION_CONFLICT` + `current` |
| Deleted by a partner, then edited | `409 VERSION_CONFLICT` + `current` with `deleted_at` set |
| Id belonging to another user's wallet | `404 NOT_FOUND`, nothing disclosed |

**Expected**: 5 of 5, each a single answer.

---

## 5. Scope exercise — SC-006

Take the four user-scoped syncable resources — Profile, Device Session, Category, Budget — and write each one's envelope from the shared schemas.

**Expected**: each composes `SyncableResource` + `UserScoped`, carries `user_id`, and carries **no** `wallet_id`. If any of the four needs a wallet identifier to be expressible, the shared schema is wrong.

---

## 6. Extension exercise — SC-007

Ask a feature author to add one endpoint to the skeleton, timed.

**Expected**: under an hour, and the diff touches only `docs/openapi/paths/<feature>.yaml`, at most one entry in `openapi.yaml`, and at most a few lines in `error-catalog.yaml`. A diff that touches `components/schemas.yaml` means the shared schemas were missing something — record what.

---

## 7. Consistency check — SC-002 and SC-003

```bash
grep -rn 'data:\|meta:\|Problem' docs/openapi/paths/ | head -40
```

**Expected**: every path file reaches the shared definitions by `$ref`. Any path file that spells out an envelope or a problem body inline fails SC-002.

For SC-003, read the catalogue top to bottom and look for two codes describing the same condition. A single pair is a defect: merge them and mark one `deprecated` with `replaced_by`.

---

## What this quickstart deliberately does not do

- It does not boot the application, hit a route or touch the database. There is no route to hit yet.
- It does not run Pint, Larastan or Pest, because no PHP file changes in this feature.
- It does not lint the OpenAPI document with a schema linter. That needs a new dependency and the owner's approval; it is recorded as follow-up F-5 in [research.md](./research.md) §R-27.
