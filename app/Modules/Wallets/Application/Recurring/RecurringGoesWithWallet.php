<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * A deleted wallet's recurring rules and their occurrences go with it, in the
 * same unit of work (spec 014, R-13, FR-028; spec 010 FR-010): each is
 * tombstoned and takes the wallet's next change_seq. A rule whose transfer
 * destination is the deleted wallet stays live in its own wallet; its
 * occurrences refuse confirmation. No transaction is touched here and no event
 * is dispatched.
 */
final class RecurringGoesWithWallet implements WalletDeletionParticipant
{
    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly OccurrenceRepository $occurrences,
    ) {}

    public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
    {
        $deletedAt = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');

        foreach ($this->rules->liveInWallet($walletId) as $rule) {
            if (! $this->rules->updateIfVersion($rule->id, $rule->version, ['deleted_at' => $deletedAt], $nextChangeSeq())) {
                throw new RuntimeException('A recurring rule changed under its wallet\'s lock.');
            }
        }

        foreach ($this->occurrences->liveInWallet($walletId) as $occurrence) {
            if (! $this->occurrences->updateIfVersion($occurrence->id, $occurrence->version, ['deleted_at' => $deletedAt], $nextChangeSeq())) {
                throw new RuntimeException('An occurrence changed under its wallet\'s lock.');
            }
        }
    }
}
