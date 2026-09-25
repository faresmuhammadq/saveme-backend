<?php

namespace App\Modules\Admin\Domain;

use App\Modules\Admin\Domain\Exceptions\InvalidAuditReason;

/**
 * Why an operator took an audited action. Trimmed, never blank, at most 500
 * characters counted as characters, not bytes (spec 006, FR-017, FR-018).
 *
 * An audited back-office method takes one of these, so it cannot run without
 * a reason.
 */
final readonly class AuditReason
{
    public const MAX_LENGTH = 500;

    private string $value;

    /**
     * @throws InvalidAuditReason
     */
    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidAuditReason::blank();
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidAuditReason::tooLong(self::MAX_LENGTH);
        }

        $this->value = $trimmed;
    }

    public function value(): string
    {
        return $this->value;
    }
}
