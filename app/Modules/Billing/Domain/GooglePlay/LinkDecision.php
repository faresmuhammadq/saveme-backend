<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * Who may own a purchase (spec 024, FR-006 to FR-010, R-3). Pure: the caller
 * resolves the facts, this decides.
 */
final readonly class LinkDecision
{
    private function __construct(public LinkOutcome $outcome, public ?string $userId = null) {}

    /**
     * @param  ?string  $linkedTo  the account the purchase is linked to now, if any
     * @param  ?string  $callerId  who is asking: the signed-in account, or null for a notification
     * @param  bool  $hasReference  the purchase carries an obfuscated account id
     * @param  ?string  $referencedUser  the account that reference resolves to, only when it is an active (not purged) one
     * @param  bool  $hasReplaced  Google names a replaced (linked) purchase token
     * @param  ?string  $replacedOwner  the account that replaced token is linked to, if we know it
     */
    public static function decide(
        ?string $linkedTo,
        ?string $callerId,
        bool $hasReference,
        ?string $referencedUser,
        bool $hasReplaced = false,
        ?string $replacedOwner = null,
    ): self {
        if ($linkedTo !== null) {
            return $callerId === null || $linkedTo === $callerId
                ? new self(LinkOutcome::AlreadyYours, $linkedTo)
                : new self(LinkOutcome::LinkedElsewhere);
        }

        // A reference that names nobody active is never linked to anyone (FR-007).
        if ($hasReference && $referencedUser === null) {
            return new self(LinkOutcome::LinkedElsewhere);
        }

        $candidate = $hasReference ? $referencedUser : null;

        // A replacing purchase inherits the replaced one's account (FR-010).
        if ($hasReplaced && $replacedOwner !== null) {
            if ($candidate !== null && $candidate !== $replacedOwner) {
                return new self(LinkOutcome::LinkedElsewhere);
            }

            $candidate = $replacedOwner;
        }

        if ($callerId === null) {
            return $candidate === null ? new self(LinkOutcome::Unlinked) : new self(LinkOutcome::Link, $candidate);
        }

        if ($candidate !== null && $candidate !== $callerId) {
            return new self(LinkOutcome::LinkedElsewhere);
        }

        return new self(LinkOutcome::Link, $callerId);
    }
}
