<?php

namespace App\Modules\Admin\Application\Operators;

use App\Modules\Admin\Application\Dto\OperatorView;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use DateTimeImmutable;

/**
 * What screens may know about an operator.
 */
final class OperatorDirectory
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly RecoveryCodeStore $recoveryCodes,
    ) {}

    public function view(string $operatorId): ?OperatorView
    {
        $operator = $this->operators->find($operatorId);

        if ($operator === null) {
            return null;
        }

        return new OperatorView(
            id: $operator->id,
            displayName: $operator->displayName,
            state: $operator->state->value,
            enrolledAt: $this->iso($operator->enrolledAt),
            recoveryCodesLeft: $this->recoveryCodes->unusedCount($operator->id),
            lastSignedInAt: $this->iso($operator->lastSignedInAt),
        );
    }

    /**
     * The email a half-finished sign-in is for, shown so the person can tell
     * "Not you?".
     */
    public function emailOf(string $operatorId): ?string
    {
        return $this->operators->find($operatorId)?->email;
    }

    private function iso(?DateTimeImmutable $instant): ?string
    {
        return $instant?->format('Y-m-d\TH:i:s.uP');
    }
}
