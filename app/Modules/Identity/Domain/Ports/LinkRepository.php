<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Account\LinkKind;

/**
 * Single-use, time-limited mailed links, stored only as hashes.
 */
interface LinkRepository
{
    /**
     * Supersedes the user's live links of this kind, then issues a new one.
     *
     * @return string the secret, readable only now
     */
    public function issue(string $userId, LinkKind $kind): string;

    /**
     * Issues the user's first link of this kind, or nothing when one was ever
     * issued: a later link (a resend) must never be superseded by an earlier
     * request that reaches the queue worker after it.
     *
     * @return string|null the secret, readable only now, or null if a link exists
     */
    public function issueFirst(string $userId, LinkKind $kind): ?string;

    /**
     * Uses a live link exactly once.
     *
     * @return string|null the user id, or null if the link is not live
     */
    public function redeem(string $secret, LinkKind $kind): ?string;

    public function supersedeAll(string $userId, LinkKind $kind): void;
}
