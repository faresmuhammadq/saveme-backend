<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\PlayPurchaseRecord;

/**
 * What reading one purchase from Google and applying it came to (spec 024,
 * R-6, R-7). A closed set of kinds; the verify and restore services turn
 * them into answers, the notification job and the passes into states.
 */
final readonly class SyncOutcome
{
    /** Read, linked or left unlinked as the rules allow, applied. */
    public const SYNCED = 'synced';

    /** Google does not know the token. */
    public const NOT_FOUND = 'not_found';

    /** Google's product is not the one named. */
    public const MISMATCH = 'mismatch';

    /** Canceled before it was ever paid. */
    public const CANCELED_UNPAID = 'canceled_unpaid';

    public const UNAVAILABLE = 'unavailable';

    /** Google's product maps to no plan; nothing was granted or acknowledged. */
    public const UNMAPPED = 'unmapped';

    /** Linked to, or by its reference naming, another account. */
    public const ELSEWHERE = 'elsewhere';

    /** Linked to an account that was purged: no effect on anyone. */
    public const PURGED = 'purged';

    public function __construct(
        public string $kind,
        public ?string $purchaseId = null,
        public ?string $userId = null,
        public bool $newlyLinked = false,
        public ?PlayPurchaseRecord $record = null,
        public bool $acknowledged = false,
        public ?string $subscriptionId = null,
        public int $retryAfterSeconds = 60,
    ) {}

    public static function of(string $kind, ?string $purchaseId = null, int $retryAfterSeconds = 60): self
    {
        return new self($kind, $purchaseId, retryAfterSeconds: $retryAfterSeconds);
    }

    public function isSynced(): bool
    {
        return $this->kind === self::SYNCED;
    }
}
