<?php

namespace App\Modules\Admin\Application\Accounts;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Lookup\LookupBudget;
use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Subscriptions\AccessQuery;
use App\Modules\Identity\Application\Operators\AccountDirectory;
use App\Modules\Identity\Application\Operators\AccountFacts;
use App\Modules\Notifications\Application\NotificationFigures;
use App\Modules\Wallets\Application\Membership\MembershipCounts;
use Illuminate\Support\Facades\DB;

/**
 * Finding and opening a person's account for an operator (spec 021, US2;
 * FR-020 to FR-025, FR-061; R-3, R-9). A search that finds an account spends
 * nothing: the page opening it leads to does, and writes `account_viewed`.
 * A search that finds nothing spends one unit and writes
 * `account_lookup_missed`, with no address or id in it. Over the budget
 * nothing is read and nothing is written.
 */
final class AccountLookup
{
    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly MembershipCounts $counts,
        private readonly AccessQuery $access,
        private readonly EntitlementService $entitlements,
        private readonly LookupBudget $budget,
        private readonly AdminAuditService $audit,
        private readonly NotificationFigures $notifications,
    ) {}

    /**
     * @return array{found: bool, user_id: ?string, by: 'address'|'id', normalised: ?string}
     *
     * @throws LookupBudgetSpent
     */
    public function find(string $operatorId, string $term): array
    {
        $this->budget->assertAvailable($operatorId);

        $term = trim($term);
        $by = str_contains($term, '@') ? 'address' : 'id';
        $facts = $by === 'address' ? $this->accounts->byAddress($term) : $this->accounts->byId($term);

        if ($facts instanceof AccountFacts) {
            return ['found' => true, 'user_id' => $facts->id, 'by' => $by, 'normalised' => $by === 'address' ? $this->accounts->normalise($term) : null];
        }

        DB::transaction(function () use ($operatorId, $by): void {
            $this->budget->spend($operatorId);
            $this->audit->accountLookupMissed($operatorId, $by);
        });

        return ['found' => false, 'user_id' => null, 'by' => $by, 'normalised' => null];
    }

    /**
     * Opens the account page's data: one unit of the budget, then the read and
     * its `account_viewed` entry together. Null (and nothing written) when the
     * account does not exist.
     *
     * @throws LookupBudgetSpent
     */
    public function view(string $operatorId, string $userId): ?AccountSnapshot
    {
        $this->budget->spend($operatorId);

        $facts = $this->accounts->byId($userId);

        if ($facts === null) {
            return null;
        }

        return DB::transaction(function () use ($operatorId, $facts): AccountSnapshot {
            $this->audit->accountViewed($operatorId, $facts->id);

            if ($facts->state === 'purged' || $facts->state === 'purging') {
                return new AccountSnapshot($facts, null, null, null);
            }

            return new AccountSnapshot(
                $facts,
                $this->counts->forUser($facts->id),
                $this->access->forUser($facts->id),
                $this->entitlements->summaryFor($facts->id),
                $this->notifications->registrationCountFor($facts->id),
            );
        });
    }

    /**
     * The subscriptions and plan as they are now, without a second recorded
     * read: for the page after a grant, extension or revocation.
     *
     * @return array{access: array<string, mixed>, plan: array<string, mixed>}
     */
    public function refreshAccess(string $userId): array
    {
        $this->entitlements->forget($userId);

        return [
            'access' => $this->access->forUser($userId)->toArray(),
            'plan' => AccountSnapshot::planToArray($this->entitlements->summaryFor($userId)),
        ];
    }

    /**
     * The plans an operator may grant.
     *
     * @return list<array{id: string, name: array{ar: string, en: string}}>
     */
    public function grantablePlans(): array
    {
        return $this->access->grantablePlans();
    }
}
