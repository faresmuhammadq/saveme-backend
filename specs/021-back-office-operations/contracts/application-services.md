# Contract: Application services (spec 021)

Signatures the screens depend on. Each lives in its owning module's `Application` layer (P-02); DTOs are `final readonly` with `toArray()` returning scalars/arrays only (P-18 rule 5). Refusals are Domain exceptions in the owning module.

## Identity

```php
namespace App\Modules\Identity\Application\Operators;

final class AccountDirectory
{
    /** Normalises like sign-in, finds by keyed lookup value; null when absent, purged or not an address. */
    public function byAddress(string $address): ?AccountFacts;
    /** Null when $id is not a UUID or absent. A purged account returns facts with state 'purged' (id and state only are meaningful). */
    public function byId(string $id): ?AccountFacts;
    /** The normalised form of an address, or null when it is not one (for "Found by …"). */
    public function normalise(string $address): ?string;
}

final readonly class AccountFacts { /* id, state, verified, createdAt, deletionScheduledFor, activeDevices */ }
```

```php
namespace App\Modules\Identity\Application;
final class Accounts { public function isActive(string $userId): bool; /* + existing */ }
```

```php
namespace App\Modules\Identity\Application\Audit;

final class AuditQuery
{
    /** Keyset page, 1 ≤ $limit ≤ 100. Never writes. Admin records the read. */
    public function page(AuditFilter $filter, ?AuditCursor $cursor, string $direction /* 'older'|'newer' */, int $limit = 50): AuditPage;
}

final readonly class AuditFilter
{
    /** @throws InvalidAuditFilter with field keys: event, actor_type, actor_id, subject_type, subject_id, from, to */
    public static function from(array $input, \DateTimeZone $operatorZone, \DateTimeImmutable $now): self;
    /** @return array<string, string> scalars for the audit_read detail */
    public function toDetail(): array;
}

final readonly class AuditCursor { public static function decode(string $token): ?self; public function encode(): string; }
```

```php
namespace App\Modules\Identity\Application\Figures;
final class AccountFigures { public function compute(\DateTimeImmutable $now): AccountFigureSet; } // total, verified, pendingDeletion, new7, new30
```

## Wallets

```php
namespace App\Modules\Wallets\Application\Membership;

final class MembershipCounts
{
    public function forUser(string $userId): MemberCounts;   // walletsOwned, walletsJoined, otherMembers (live rows only)
    public function sharedWallets(): int;                     // live wallets with ≥ 2 live members
}
```

## Billing

```php
namespace App\Modules\Billing\Application\Claims;

final class ClaimQuery
{
    // existing: queue(), duplicatesOf(), detail()   — detail() gains ClaimDetail::$matchesCurrentPrice (bool)
    /** No address in any item. $search: ['reference' => string] | ['lookup' => string] | null. */
    public function page(ClaimFilter $status, ?array $search, ?string $after, int $limit = 25): ClaimPage;
    /** @return array{count: int, oldest: ?\DateTimeImmutable} submitted and unexpired */
    public function awaitingSummary(): array;
    /** Address-free refresh after an action. */
    public function reviewState(string $claimId): ?ClaimReviewState;
    /** Normalises and turns an address into its lookup value, or null when not an address (pure). */
    public function lookupFor(string $address): ?string;
}
```

```php
namespace App\Modules\Billing\Application\Subscriptions;

final class AccessQuery { public function forUser(string $userId): AccessView; }

final class OperatorSubscriptions
{
    /** @throws PlanNotGrantable|AccountNotActive|InvalidReason|InvalidDays */
    public function grant(string $operatorId, string $userId, string $planId, ?int $days, string $reason): SubscriptionRecord;
    /** @throws SubscriptionNotFound|SubscriptionRevoked|SubscriptionNotExtendable|AccountNotActive|InvalidReason|InvalidDays */
    public function extend(string $operatorId, string $subscriptionId, int $days, string $reason): SubscriptionRecord;
    /** @throws SubscriptionNotFound|SubscriptionNotRevocable|InvalidReason */
    public function revoke(string $operatorId, string $subscriptionId, string $reason): void;
}
```

Days: `null` (grant only, for life) or 1–3,650 (`InvalidDays` otherwise — the upper bound is new).

```php
namespace App\Modules\Billing\Application\Plans;

final class PlanAdminQuery
{
    /** @return list<PlanAdminView> every plan, display order; never the code */
    public function all(): array;
    public function find(string $planId): ?PlanAdminView;
    /** Validates without writing. @return list<string> changed field names @throws PlanEditInvalid|NothingToSave */
    public function preview(string $planId, PlanChangeSet $changes): array;
}

final class PlanAdministration
{
    /** One transaction. @return list<string> changed field names, sorted
     *  @throws PlanEditInvalid|NothingToSave|PlanChangedElsewhere */
    public function update(string $planId, string $version, PlanChangeSet $changes): array;
    /** @throws PlanNotRetirable (default, already retired, unknown)|PlanChangedElsewhere */
    public function retire(string $planId, string $version): void;
}

final readonly class PlanChangeSet
{
    /** Built from the editor's draft: name.en, name.ar, sort_order, instapay_price {amount_minor, currency}|null, features map. */
    public static function fromDraft(array $draft): self;
}
```

```php
namespace App\Modules\Billing\Application\Figures;
final class BillingFigures { public function compute(\DateTimeImmutable $now): BillingFigureSet; } // paidGooglePlay, paidInstapay, grantedManual, inGrace
```

## Admin

```php
namespace App\Modules\Admin\Application\Accounts;

final class AccountLookup
{
    /** Checks the budget is not spent, then looks up; a miss spends one unit and writes account_lookup_missed; a hit spends nothing (the page open does).
     *  @return array{found: bool, user_id: ?string, by: 'address'|'id', normalised: ?string}
     *  @throws LookupBudgetSpent (minutes to wait) */
    public function find(string $operatorId, string $term): array;
    /** Spends one budget unit, then writes account_viewed in one transaction with the read; null when absent (unit spent, nothing written). @throws LookupBudgetSpent */
    public function view(string $operatorId, string $userId): ?AccountSnapshot; // facts + counts + access + entitlement summary
}
```

```php
namespace App\Modules\Admin\Application\Claims;
final class ClaimReading { /** claim_address_read, then detail; null when absent (nothing written). */ public function open(string $operatorId, string $claimId): ?ClaimDetail; }
```

```php
namespace App\Modules\Admin\Application\Audit;
final class AuditReader
{
    /** Writes one audit_read (subject = $operatorId, detail = filter + page) and runs the query, in one transaction. */
    public function read(string $operatorId, AuditFilter $filter, ?AuditCursor $cursor, string $page /* first|older|newer */): AuditPage;
}
// AdminAuditService gains: accountViewed(op, user), accountLookupMissed(op, by), planChanged(op, plan, AdminAction, list<string> fields, string reason), auditRead(op, array detail)
```

```php
namespace App\Modules\Admin\Application\Plans;
final class PlanChanges
{
    /** DB::transaction { PlanAdministration::update; AdminAuditService::planChanged(PlanUpdated) }. */
    public function update(string $operatorId, string $planId, string $version, PlanChangeSet $changes, string $reason): array;
    public function retire(string $operatorId, string $planId, string $version, string $reason): void;
}
```

```php
namespace App\Modules\Admin\Application\Lookup;
final class LookupBudget { /** @throws LookupBudgetSpent */ public function spend(string $operatorId): void; } // 30 per 600 s
```

```php
namespace App\Modules\Admin\Application\Notifications;
final class NotificationReadMarks
{
    public function markRead(string $operatorId, string $notificationId): void;   // idempotent
    public function markAllRead(string $operatorId): void;                        // within the unread window
}
// OperatorNotificationQueryService: latestFor(string $operatorId, int $limit = 20), unreadCountFor(string $operatorId)
```

```php
namespace App\Modules\Admin\Application\Overview;
final class OverviewFigures { /** @return array<string, int|'fewer_than_10'> plus exact needs-attention values */ public function compute(): array; }
```
