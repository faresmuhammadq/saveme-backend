<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use App\Modules\Wallets\Domain\Recurring\Refusals\OccurrenceNotFound;
use App\Modules\Wallets\Domain\Recurring\Refusals\RecurringRuleNotFound;

/**
 * Reads a wallet's recurring rules and occurrences for any active member
 * (row R-01, spec 014, FR-022). A non-member, and everyone once the wallet
 * is deleted, gets not-found; so does a rule or occurrence of another wallet
 * (a transfer rule is read under its source wallet only).
 */
final class RecurringQueryService
{
    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly OccurrenceRepository $occurrences,
        private readonly WalletRepository $wallets,
        private readonly WalletAuthorizer $authorizer,
    ) {}

    /**
     * The wallet's live rules, every status.
     *
     * @param  array<string, list<array{op: string, value: string}>>  $filters
     * @param  'created_at'|'start_date'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<RuleView>, next: array{value: string, id: string}|null}
     */
    public function listRules(string $userId, string $walletId, array $filters, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $this->assertReadable($userId, $walletId);

        $rows = $this->rules->listLive($walletId, $filters, $sort, $descending, $after, $limit);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $this->rules->sortValue($last, $sort), 'id' => $last->id];
        }

        return ['items' => array_map(fn ($rule): RuleView => new RuleView($rule), $page), 'next' => $next];
    }

    /**
     * One rule of the wallet, live or tombstoned.
     */
    public function findRule(string $userId, string $walletId, string $ruleId): RuleView
    {
        $this->assertReadable($userId, $walletId);

        $rule = $this->rules->find($ruleId);

        if ($rule === null || $rule->walletId !== $walletId) {
            throw new RecurringRuleNotFound;
        }

        return new RuleView($rule);
    }

    /**
     * The wallet's live occurrences.
     *
     * @param  array<string, list<array{op: string, value: string}>>  $filters
     * @param  'date'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<OccurrenceView>, next: array{value: string, id: string}|null}
     */
    public function listOccurrences(string $userId, string $walletId, array $filters, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $this->assertReadable($userId, $walletId);

        $rows = $this->occurrences->listLive($walletId, $filters, $sort, $descending, $after, $limit);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $this->occurrences->sortValue($last, $sort), 'id' => $last->id];
        }

        return ['items' => array_map(fn ($occurrence): OccurrenceView => new OccurrenceView($occurrence), $page), 'next' => $next];
    }

    /**
     * One occurrence of the wallet, live or tombstoned.
     */
    public function findOccurrence(string $userId, string $walletId, string $occurrenceId): OccurrenceView
    {
        $this->assertReadable($userId, $walletId);

        $occurrence = $this->occurrences->find($occurrenceId);

        if ($occurrence === null || $occurrence->walletId !== $walletId) {
            throw new OccurrenceNotFound;
        }

        return new OccurrenceView($occurrence);
    }

    private function assertReadable(string $userId, string $walletId): void
    {
        $this->authorizer->authorize($userId, $walletId, Permission::R01ReadRecurring);

        if ($this->wallets->find($walletId)?->isTombstone() ?? true) {
            throw new RecurringRuleNotFound;
        }
    }
}
