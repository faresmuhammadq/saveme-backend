<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Application\Activity\WalletActivity;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Categories\WalletCategoryUses;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Recurring\Dto\SavedRecurringRule;
use App\Modules\Wallets\Application\Transactions\SharedBudgetLink;
use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRules;
use App\Modules\Wallets\Domain\Recurring\Refusals\RecurringRuleNotFound;
use App\Modules\Wallets\Domain\Recurring\Refusals\RecurringRuleVersionConflict;
use App\Modules\Wallets\Domain\Recurring\Refusals\RecurringStateInvalid;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use App\Modules\Wallets\Domain\Recurring\RuleToday;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

/**
 * Every write to a recurring rule (spec 014, FR-001 to FR-008, FR-021). Each
 * is one unit of work of the database under the fixed lock order of
 * RecurringLocks; the rule and every occurrence it changes take their
 * `change_seq` from the wallet's counter, then the wallet its final stamp.
 *
 * Precedence for every write: not-found, forbidden on the path wallet, a
 * write to a tombstone, an identical replay, a stale version, forbidden in a
 * transfer's destination, then the state rules, collected into one refusal.
 * No amount, description, memo or counterparty is ever logged (P-15).
 *
 * @phpstan-import-type Values from RecurringRuleRecord
 */
final class RecurringRuleService
{
    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly OccurrenceRepository $occurrences,
        private readonly MembershipRepository $members,
        private readonly RecurringLocks $locks,
        private readonly RecurringStamps $stamps,
        private readonly RecurringCategoryChecks $categoryChecks,
        private readonly CategoryReferences $references,
        private readonly WalletCategoryUses $categoryUses,
        private readonly Materialiser $materialiser,
        private readonly OccurrenceReconciler $reconciler,
        private readonly AutoConfirmer $autoConfirmer,
        private readonly WalletAuthorizer $authorizer,
        private readonly Profiles $profiles,
        private readonly WalletActivity $activity,
        private readonly SharedBudgetLink $sharedBudgets,
    ) {}

    /**
     * Creates the rule (`$version` 0) or replaces its writable members; the
     * rule's occurrences are materialised or reconciled in the same unit of
     * work. A new rule belongs to the path wallet (for a transfer, the
     * source).
     *
     * @param  array<string, mixed>  $values  the writable members; an absent `shared_budget_id` keeps the stored link
     * @param  Closure(RuleView): array<string, mixed>  $present  how a read renders the rule, for a conflict's `current`
     */
    public function save(string $actorId, string $walletId, string $ruleId, array $values, int $version, Closure $present): SavedRecurringRule
    {
        /** @var array<string, mixed> $values */
        $values = self::normalise($values);

        return $this->locks->run(
            $actorId,
            $walletId,
            $ruleId,
            null,
            [$values['category_id'], $values['payout_category_id']],
            $values['counter_wallet_id'],
            null,
            function (?RecurringRuleRecord $stored, ?OccurrenceRecord $unused, array $wallets) use ($actorId, $walletId, $ruleId, $values, $version, $present): SavedRecurringRule {
                if (! array_key_exists('shared_budget_id', $values)) {
                    $values['shared_budget_id'] = $stored?->sharedBudgetId;
                }

                /** @var Values $values */
                $this->checkPath($actorId, $walletId, $ruleId, $stored, $wallets, $version, mayCreate: true);

                if ($stored === null) {
                    return new SavedRecurringRule($this->create($actorId, $walletId, $ruleId, $values, $wallets), true);
                }

                $this->refuseTombstone($stored, $present);

                if ($values === $stored->writableValues()) {
                    return new SavedRecurringRule(new RuleView($stored), false);
                }

                $this->refuseStale($stored, $version, $present);
                $this->authorizeDestination($actorId, $stored, $wallets);

                return new SavedRecurringRule($this->update($actorId, $stored, $values), false);
            },
            fn () => $this->conflictOrNotFound($walletId, $ruleId, $present),
        );
    }

    /**
     * Deletes the rule and all its occurrences: tombstones, final (FR-008).
     * Deleting a tombstone returns it. The transactions the rule produced are
     * untouched.
     *
     * @param  Closure(RuleView): array<string, mixed>  $present
     */
    public function delete(string $actorId, string $walletId, string $ruleId, int $version, Closure $present): RuleView
    {
        return $this->locks->run(
            $actorId,
            $walletId,
            $ruleId,
            null,
            [],
            null,
            null,
            function (?RecurringRuleRecord $stored, ?OccurrenceRecord $unused, array $wallets) use ($actorId, $walletId, $ruleId, $version, $present): RuleView {
                $this->checkPath($actorId, $walletId, $ruleId, $stored, $wallets, $version, mayCreate: false);
                $stored ?? throw new RecurringRuleNotFound;

                if ($stored->isTombstone()) {
                    return new RuleView($stored);
                }

                $this->refuseStale($stored, $version, $present);
                $this->authorizeDestination($actorId, $stored, $wallets);

                $deletedAt = self::instant(now()->toDateTimeImmutable());
                $ids = array_map(fn (OccurrenceRecord $occurrence): string => $occurrence->id, $this->occurrences->lockLiveForRule($ruleId));

                if ($ids !== []) {
                    $this->occurrences->updateMany($ids, ['deleted_at' => $deletedAt], $this->stamps->block($walletId, count($ids)));
                }

                if (! $this->rules->updateIfVersion($stored->id, $stored->version, ['deleted_at' => $deletedAt], $this->stamps->next($walletId))) {
                    throw new RuntimeException('The recurring rule changed under its own lock.');
                }

                $this->activity->record($walletId, $actorId, ActivityAction::Deleted, ActivitySubject::RecurringRule, $ruleId);
                $this->stamps->finish($walletId);

                return new RuleView($this->reload($ruleId));
            },
            fn () => $this->conflictOrNotFound($walletId, $ruleId, $present),
        );
    }

    /**
     * Catches the rule up now, as the hourly job would (FR-021): extends its
     * occurrences through the horizon and, for an active automatic rule,
     * confirms what is due. Idempotent; a paused or ended rule comes back
     * unchanged; a tombstone is a conflict carrying it.
     *
     * @param  Closure(RuleView): array<string, mixed>  $present
     */
    public function materialise(string $actorId, string $walletId, string $ruleId, Closure $present): RuleView
    {
        return $this->locks->run(
            $actorId,
            $walletId,
            $ruleId,
            null,
            [],
            null,
            fn (?RecurringRuleRecord $rule): array => $this->dueCategories($rule),
            function (?RecurringRuleRecord $stored, ?OccurrenceRecord $unused, array $wallets) use ($actorId, $walletId, $ruleId, $present): RuleView {
                $this->checkPath($actorId, $walletId, $ruleId, $stored, $wallets, 0, mayCreate: false);
                $stored ?? throw new RecurringRuleNotFound;
                $this->refuseTombstone($stored, $present);
                $this->authorizeDestination($actorId, $stored, $wallets);

                $this->catchUpLocked($stored);

                return new RuleView($this->reload($ruleId));
            },
            fn () => $this->conflictOrNotFound($walletId, $ruleId, $present),
        );
    }

    /**
     * One pass of the hourly job over one rule, in its own unit of work and
     * with nobody's authority but the rule author's (FR-020). A rule that is
     * gone, deleted or ended is skipped. Given the rule as the job read it, a
     * rule with nothing to materialise and nothing due is skipped after two
     * reads, before any lock is taken; the locked pass decides again.
     *
     * @return array{materialised: int, confirmed: int, refused: int}
     */
    public function catchUp(string $ruleId, ?RecurringRuleRecord $known = null): array
    {
        $none = ['materialised' => 0, 'confirmed' => 0, 'refused' => 0];
        $rule = $known ?? $this->rules->find($ruleId);

        if ($rule === null || $rule->isTombstone() || $rule->status !== RuleStatus::Active) {
            return $none;
        }

        if ($known !== null && ! $this->hasWork($rule)) {
            return $none;
        }

        return $this->locks->run(
            null,
            $rule->walletId,
            $ruleId,
            null,
            [],
            null,
            fn (?RecurringRuleRecord $current): array => $this->dueCategories($current),
            function (?RecurringRuleRecord $stored, ?OccurrenceRecord $unused, array $wallets) use ($rule, $none): array {
                $wallet = $wallets[$rule->walletId] ?? null;

                if ($stored === null || $stored->walletId !== $rule->walletId || $stored->isTombstone() || $stored->status !== RuleStatus::Active || $wallet === null || $wallet->isTombstone()) {
                    return $none;
                }

                return $this->catchUpLocked($stored);
            },
            fn (): array => $none,
            $known,
        );
    }

    /**
     * Whether a pass over the rule would do anything, read without a lock.
     */
    private function hasWork(RecurringRuleRecord $rule): bool
    {
        $today = RuleToday::of($rule->timezone, now()->toDateTimeImmutable());

        return $this->materialiser->isBehind($rule, $today)
            || ($rule->autoConfirm && $this->occurrences->hasDueForAutoConfirm($rule->id, $rule->autoConfirmFrom, $today));
    }

    /**
     * @return array{materialised: int, confirmed: int, refused: int}
     */
    private function catchUpLocked(RecurringRuleRecord $rule): array
    {
        $today = RuleToday::of($rule->timezone, now()->toDateTimeImmutable());

        $materialised = $this->materialiser->extend($rule, $today);
        $confirmation = $this->autoConfirmer->confirmDue($rule, $today);

        if ($materialised + $confirmation['confirmed'] + $confirmation['refused'] > 0) {
            $this->stamps->finish($rule->walletId);
        }

        return ['materialised' => $materialised, ...$confirmation];
    }

    /**
     * The categories of the occurrences the next pass would confirm, probed
     * before the locks so they are share-locked first (FR-026).
     *
     * @return list<string>
     */
    private function dueCategories(?RecurringRuleRecord $rule): array
    {
        if ($rule === null || $rule->isTombstone() || $rule->status !== RuleStatus::Active || ! $rule->autoConfirm) {
            return [];
        }

        $today = RuleToday::of($rule->timezone, now()->toDateTimeImmutable());

        return $this->occurrences->dueCategoryIds($rule->id, $rule->autoConfirmFrom, $today, (int) config('wallets.recurring.max_confirms_per_pass'));
    }

    /**
     * Not-found, then forbidden, on the path wallet and the rule id.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function checkPath(string $actorId, string $walletId, string $ruleId, ?RecurringRuleRecord $stored, array $wallets, int $version, bool $mayCreate): void
    {
        $wallet = $wallets[$walletId] ?? null;

        if ($wallet === null || $wallet->isTombstone()) {
            throw new RecurringRuleNotFound;
        }

        $this->authorizer->memberRole($actorId, $walletId);

        if ($stored === null) {
            if (! $mayCreate || $version > 0 || $this->rules->isHeldByOther($ruleId, $walletId)) {
                throw new RecurringRuleNotFound;
            }
        } elseif ($stored->walletId !== $walletId) {
            throw new RecurringRuleNotFound;
        }

        $this->authorizer->authorize($actorId, $walletId, Permission::R02WriteRecurring);
    }

    /**
     * A transfer rule also needs T-02 in the destination on every write
     * (FR-029, row T-03): a destination that is deleted is skipped, so the
     * rule can still be paused, ended and deleted; one the caller is no
     * longer a member of is forbidden.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function authorizeDestination(string $actorId, RecurringRuleRecord $rule, array $wallets): void
    {
        if ($rule->counterWalletId === null) {
            return;
        }

        $destination = $wallets[$rule->counterWalletId] ?? null;

        if ($destination === null || $destination->isTombstone()) {
            return;
        }

        if ($this->members->activeFor($destination->id, $actorId) === null) {
            throw new WalletActionForbidden;
        }

        $this->authorizer->authorize($actorId, $destination->id, Permission::T03Transfer);
    }

    /**
     * @param  Values  $values
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function create(string $actorId, string $walletId, string $ruleId, array $values, array $wallets): RuleView
    {
        $wallet = $wallets[$walletId] ?? throw new RecurringRuleNotFound;
        $destination = $this->destinationOf($actorId, $values, $walletId, $wallets);

        if ($destination !== null) {
            $this->authorizer->authorize($actorId, $destination->id, Permission::T03Transfer);
        }

        $errors = RecurringRuleRules::violations($values, null, null);

        if ($values['currency'] !== $wallet->currency) {
            $errors[] = new FieldError('/currency', ErrorCode::CurrencyMismatch);
        }

        if ($values['kind'] === TransactionKind::Transfer->value && $values['counter_wallet_id'] !== null) {
            if ($destination === null) {
                $errors[] = new FieldError('/counter_wallet_id', ErrorCode::InvalidEnumValue);
            } elseif ($destination->currency !== $wallet->currency) {
                $errors[] = new FieldError('/counter_wallet_id', ErrorCode::CurrencyMismatch);
            }
        }

        array_push($errors, ...$this->categoryErrors($actorId, $values, null));
        array_push($errors, ...$this->budgetErrors($actorId, $values, null));
        $this->refuseInvalid($errors);

        $timezone = $this->timezoneOf($actorId);
        $today = RuleToday::of($timezone, now()->toDateTimeImmutable());

        $this->rules->insert($ruleId, $walletId, self::columns($values), $timezone, $actorId, $today, $this->stamps->next($walletId));

        foreach ([$values['category_id'], $values['payout_category_id']] as $categoryId) {
            if ($categoryId !== null) {
                $this->references->publish($walletId, $categoryId);
            }
        }

        $rule = $this->reload($ruleId);
        $this->materialiser->extend($rule, $today);
        $this->activity->record($walletId, $actorId, ActivityAction::Created, ActivitySubject::RecurringRule, $ruleId);
        $this->stamps->finish($walletId);

        return new RuleView($rule);
    }

    /**
     * The shared update path on the locked live rule.
     *
     * @param  Values  $values  the full writable values wanted
     */
    private function update(string $actorId, RecurringRuleRecord $stored, array $values): RuleView
    {
        $errors = RecurringRuleRules::violations($values, $stored, $this->occurrences->latestResolved($stored->id));
        array_push($errors, ...$this->categoryErrors($actorId, $values, $stored));
        array_push($errors, ...$this->budgetErrors($actorId, $values, $stored));
        $this->refuseInvalid($errors);

        $today = RuleToday::of($stored->timezone, now()->toDateTimeImmutable());
        $current = $stored->writableValues();

        $columns = [];
        foreach (self::columns($values) as $column => $value) {
            if ($current[$column] !== $values[$column]) {
                $columns[$column] = $value;
            }
        }

        $resumed = $stored->status === RuleStatus::Paused && $values['status'] === RuleStatus::Active->value;
        $switchedOn = ! $stored->autoConfirm && $values['auto_confirm'];

        if ($resumed || $switchedOn) {
            $columns['auto_confirm_from'] = $today;
        }

        if (! $this->rules->updateIfVersion($stored->id, $stored->version, $columns, $this->stamps->next($stored->walletId))) {
            throw new RuntimeException('The recurring rule changed under its own lock.');
        }

        $rule = $this->reload($stored->id);

        $this->reconciler->reconcile($rule, $today);
        $this->materialiser->extend($rule, $today);

        $this->moveReference($stored->walletId, $stored->categoryId, $rule->categoryId);
        $this->moveReference($stored->walletId, $stored->payoutCategoryId, $rule->payoutCategoryId);

        $this->activity->record($stored->walletId, $actorId, ActivityAction::Edited, ActivitySubject::RecurringRule, $stored->id);
        $this->stamps->finish($stored->walletId);

        return new RuleView($rule);
    }

    /**
     * Publishes the reference to the new category and withdraws the old one's
     * when nothing in the wallet, live or tombstoned, uses it any more.
     */
    private function moveReference(string $walletId, ?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        if ($after !== null) {
            $this->references->publish($walletId, $after);
        }

        if ($before !== null && ! $this->categoryUses->usedIn($walletId, $before)) {
            $this->references->withdrawIfUnused($walletId, $before);
        }
    }

    /**
     * The Shared Budget a rule names (ADR-0027 rule 4): only its author changes
     * the link, and a new link must be one they may use. A link that stays as
     * stored is never refused.
     *
     * @param  Values  $values
     * @return list<FieldError>
     */
    private function budgetErrors(string $actorId, array $values, ?RecurringRuleRecord $stored): array
    {
        $budgetId = $values['shared_budget_id'];

        if ($budgetId === $stored?->sharedBudgetId) {
            return [];
        }

        if ($stored !== null && $actorId !== $stored->createdBy) {
            return [new FieldError('/shared_budget_id', ErrorCode::InvalidEnumValue)];
        }

        if ($budgetId === null) {
            return [];
        }

        $code = $this->sharedBudgets->refusal($actorId, $budgetId, $values['kind'], $values['currency'], $values['category_id']);

        return $code === null ? [] : [new FieldError('/shared_budget_id', $code)];
    }

    /**
     * The category and payout category of the write, checked as B6 checks a
     * transaction's; a member of the stored rule that is unchanged stays.
     *
     * @param  Values  $values
     * @return list<FieldError>
     */
    private function categoryErrors(string $actorId, array $values, ?RecurringRuleRecord $stored): array
    {
        $errors = [];

        if ($values['kind'] !== TransactionKind::Transfer->value) {
            $error = $this->categoryChecks->error($actorId, $values['kind'], $values['category_id'], $stored?->categoryId, '/category_id');

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        $error = $this->categoryChecks->error($actorId, TransactionKind::Income->value, $values['payout_category_id'], $stored?->payoutCategoryId, '/payout_category_id');

        if ($error !== null) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * A transfer's destination when it is a live wallet other than the
     * source and the caller is a member of it.
     *
     * @param  Values  $values
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function destinationOf(string $actorId, array $values, string $walletId, array $wallets): ?WalletRecord
    {
        $id = $values['counter_wallet_id'];

        if ($values['kind'] !== TransactionKind::Transfer->value || $id === null || $id === $walletId) {
            return null;
        }

        $destination = $wallets[$id] ?? null;

        if ($destination === null || $destination->isTombstone() || $this->members->activeFor($id, $actorId) === null) {
            return null;
        }

        return $destination;
    }

    /**
     * @param  list<FieldError>  $errors
     */
    private function refuseInvalid(array $errors): void
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->pointer] ??= $error;
        }

        if ($unique !== []) {
            throw new RecurringStateInvalid(array_values($unique));
        }
    }

    private function timezoneOf(string $userId): string
    {
        try {
            return $this->profiles->find($userId)->timezone;
        } catch (ModelNotFoundException) {
            return (string) config('identity.default_timezone');
        }
    }

    /**
     * The writable values as columns (their names are the members').
     *
     * @param  Values  $values
     * @return array<string, scalar|null>
     */
    private static function columns(array $values): array
    {
        return $values;
    }

    /**
     * @param  Closure(RuleView): array<string, mixed>  $present
     */
    private function refuseTombstone(RecurringRuleRecord $stored, Closure $present): void
    {
        if ($stored->isTombstone()) {
            throw new RecurringRuleVersionConflict($present(new RuleView($stored)));
        }
    }

    /**
     * @param  Closure(RuleView): array<string, mixed>  $present
     */
    private function refuseStale(RecurringRuleRecord $stored, int $version, Closure $present): void
    {
        if ($version !== $stored->version) {
            throw new RecurringRuleVersionConflict($present(new RuleView($stored)));
        }
    }

    /**
     * What a write answers when the locks never settle: the stored rule, as a
     * conflict, or not-found.
     *
     * @param  Closure(RuleView): array<string, mixed>  $present
     */
    private function conflictOrNotFound(string $walletId, string $ruleId, Closure $present): never
    {
        $current = $this->rules->find($ruleId);

        if ($current === null || $current->walletId !== $walletId) {
            throw new RecurringRuleNotFound;
        }

        throw new RecurringRuleVersionConflict($present(new RuleView($current)));
    }

    private function reload(string $ruleId): RecurringRuleRecord
    {
        return $this->rules->find($ruleId) ?? throw new RecurringRuleNotFound;
    }

    /**
     * Text is stored trimmed, a blank one as null.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function normalise(array $values): array
    {
        foreach (['description', 'memo', 'counterparty'] as $member) {
            if (array_key_exists($member, $values) && is_string($values[$member])) {
                $trimmed = trim($values[$member]);
                $values[$member] = $trimmed === '' ? null : $trimmed;
            }
        }

        return $values;
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
