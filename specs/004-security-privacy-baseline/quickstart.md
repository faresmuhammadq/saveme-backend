# Quickstart: Validating the Security & Privacy Baseline

**Feature**: `004-security-privacy-baseline` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

This feature ships two documents and ten reserved error codes. No application code changes, so validation is mechanical checks over the files plus four reviewer exercises that map onto the success criteria. Run everything from the repository root.

## Prerequisites

- The deliverables exist: `docs/security-baseline.md`, `docs/privacy-commitments.md`, and the ten additions to `docs/openapi/error-catalog.yaml`.
- `symfony/yaml` is already installed as a transitive dependency, so the catalogue checks need nothing new.
- No database, no server, no `dev project open`. Nothing here boots the application, because there is nothing yet to boot.

---

## 1. Structural checks

### 1.1 The catalogue grew by exactly ten, and stays consistent

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

**Expected**: `catalogue and enum agree (48 codes)` — the 38 from spec 003 plus the ten reserved here.

### 1.2 The ten new codes are complete and correctly classified

```bash
php -r 'require "vendor/autoload.php";
  $d = Symfony\Component\Yaml\Yaml::parseFile("docs/openapi/error-catalog.yaml")["x-error-catalog"];
  $new = ["INVALID_CREDENTIALS"=>401, "ACCOUNT_LOCKED"=>423, "EMAIL_NOT_VERIFIED"=>403,
          "VERIFICATION_TOKEN_INVALID"=>400, "RESET_TOKEN_INVALID"=>400, "TWO_FACTOR_REQUIRED"=>401,
          "TWO_FACTOR_INVALID"=>401, "INSECURE_TRANSPORT"=>400, "PROVIDER_SIGNATURE_INVALID"=>401,
          "EXPORT_IN_PROGRESS"=>409];
  $bad = 0;
  foreach ($new as $code => $status) {
    if (!isset($d[$code])) { echo "MISSING  $code\n"; $bad++; continue; }
    if ($d[$code]["status"] !== $status) { echo "STATUS   $code is {$d[$code]["status"]}, expected $status\n"; $bad++; }
    foreach (["level","title","meaning","retryable","since"] as $k) {
      if (!isset($d[$code][$k])) { echo "FIELD    $code missing $k\n"; $bad++; }
    }
  }
  echo $bad === 0 ? "all ten reserved codes present and correct\n" : "$bad problems\n";'
```

**Expected**: `all ten reserved codes present and correct`.

### 1.3 No retention period is left vague

```bash
grep -niE '\b(a reasonable time|reasonable period|as soon as possible|promptly|in a timely)\b' \
  docs/security-baseline.md docs/privacy-commitments.md | grep -viE 'never says|nothing here says|cannot check'
```

**Expected**: no matches. FR-029 requires every period to be a number a reviewer can check. The second filter drops the one sentence that quotes the phrase in order to ban it.

### 1.4 Nothing claims blanket encryption

```bash
grep -niE 'all (your )?data is encrypted|everything is encrypted|fully encrypted|end-to-end' \
  docs/security-baseline.md docs/privacy-commitments.md | grep -viE 'would be|is not|never claim|rather than|rather tell'
```

**Expected**: no matches. Amounts and notes are deliberately unencrypted at rest, and FR-015 forbids an unqualified claim. A match is a promise the system does not keep. The second filter drops the sentences that quote the phrase in order to reject it — the baseline explains why "everything is encrypted" would be false here, and that explanation is the point rather than a violation.

### 1.5 Every commitment maps to a requirement

```bash
php -r '
  $c = file_get_contents("docs/privacy-commitments.md");
  $s = file_get_contents("specs/004-security-privacy-baseline/spec.md");
  preg_match_all("/FR-[0-9]{3}[a-c]?/", $c, $m);
  $used = array_unique($m[0]);
  $missing = array_values(array_filter($used, fn($fr) => !str_contains($s, "**$fr**")));
  echo "commitments cite ".count($used)." requirements\n";
  echo $missing ? "NOT IN SPEC: ".implode(", ", $missing)."\n" : "every cited requirement exists\n";'
```

**Expected**: every cited requirement exists. FR-036 makes a promise without a backing rule a defect; this is that check, mechanised.

### 1.6 Both languages are present

```bash
grep -c '^## ' docs/privacy-commitments.md
php -r '$c = file_get_contents("docs/privacy-commitments.md");
  echo "Arabic present: ".(preg_match("/\p{Arabic}/u", $c) ? "yes" : "NO")."\n";
  echo "English present: ".(preg_match("/[A-Za-z]{4,}/", $c) ? "yes" : "NO")."\n";'
```

**Expected**: both `yes`. A copy change that lands in one language and not the other is the common failure, which is why both live in one file.

---

## 2. Reviewer exercise — SC-009

Give a reviewer the baseline and [the checklist](./contracts/security-review-checklist.md), then these five seeded violations. Ask which rule each breaks.

```text
1. logger.info("transfer completed", { amount_minor: -12500, wallet: "0192f7…" })
2. 403 { "detail": "Fares already has a wallet named Groceries" }
3. PUT /api/v1/transactions/{id}  — succeeds for a user who has never verified their address
4. POST /billing/provider-callback — parses the JWT body, does not check the audience
5. privacy page: "Deleted data is removed from our systems within a reasonable time."
```

**Expected**: 5 of 5 identified.

| # | Violation | Rule |
|---|---|---|
| 1 | An amount logged at informational level | E2 — amounts are debug-only, never at info or above |
| 2 | An error naming another person and their wallet | E5 — no message names another user, wallet or value |
| 3 | A write by an unverified account | C1 — every wallet write sits behind the verification gate |
| 4 | No audience check on a provider message | H4 — signature, issuer, **audience**, time window and replay |
| 5 | A retention period that is not a number | G1 and FR-029 — every period is a number a reviewer can check |

A good reviewer also notices that finding 2 should have been a not-found rather than a forbidden, since the caller is evidently outside that wallet.

---

## 3. Downstream-author exercise — SC-008

Hand someone specifying Identity, Billing or Sharing only `docs/security-baseline.md`. Ask them to list what their feature must do about sessions, verification, protected attributes, recorded events and retention.

**Expected**: they produce all five with **zero** clarifying questions:

- which credential their endpoints accept, and what revocation must do;
- whether their writes sit behind the verification gate;
- which of their stored attributes are protected, and how a lookup by address works;
- which events they must record, from the fixed vocabulary, and what may never be inside an entry;
- every retention period their feature touches, as a number.

Any question asked is a gap in the document, recorded as a defect against it.

---

## 4. Lockout exercise — the edge case that decides the rule

Walk the rule by hand for two attackers and one owner.

| Scenario | Expected outcome |
|---|---|
| One address, 6 wrong passwords | refused for 15 minutes, not longer |
| One address, 11 wrong passwords | refused for 60 minutes, and never beyond that |
| An attacker locking a victim out repeatedly | the owner resets by mail and is back in immediately |
| 20 failures from one origin across 20 different accounts | that origin refused for 15 minutes, no account locked |
| The owner signs in correctly | the counter resets |

**Expected**: 5 of 5 match, and no path exists by which an attacker denies the owner access for longer than an hour without controlling their mailbox.

---

## 5. Commitment audit — SC-007

Read `docs/privacy-commitments.md` top to bottom as a sceptical user.

**Expected**: every sentence is either backed by a requirement in the mapping table, or is plainly a statement of fact about the product. No sentence survives that a reviewer could not trace to a rule. Pay particular attention to:

- the retention sentence, which must say 90 days for anything that syncs, not 30;
- the encryption sentence, which must name what is protected rather than claiming everything is;
- the deletion sentence, which must not promise removal of a shared wallet's history;
- the shared-wallet sentence, which must say that another member's device keeps what it already synced.

---

## What this quickstart deliberately does not do

- It does not test a running system, because this feature builds none. The rules are tested by the features that implement them, and each of those owes feature tests for the invariants it introduces.
- It does not run Pint, Larastan or Pest, because no PHP file changes here.
- It does not scan for vulnerabilities. A scanner is a dependency and a different job; the check this feature owes is that a human can review one endpoint against one rule.
