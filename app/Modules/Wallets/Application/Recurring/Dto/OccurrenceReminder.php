<?php

namespace App\Modules\Wallets\Application\Recurring\Dto;

/**
 * An occurrence that needs people now, and who they are (spec 023, R-12):
 * `kind` is `due` (a manual occurrence dated the rule's today or yesterday)
 * or `refused` (the server's automatic confirmation was refused). Identifiers
 * only: no amount, no description.
 */
final readonly class OccurrenceReminder
{
    /**
     * @param  list<string>  $recipientUserIds  active members the permission matrix lets confirm it
     */
    public function __construct(
        public string $occurrenceId,
        public string $walletId,
        public string $kind,
        public array $recipientUserIds,
    ) {}
}
