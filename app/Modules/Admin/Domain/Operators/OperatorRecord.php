<?php

namespace App\Modules\Admin\Domain\Operators;

use DateTimeImmutable;

/**
 * An Operator Account as the application sees it, with sealed values opened.
 * Never serialised, logged or put in Livewire state.
 */
final readonly class OperatorRecord
{
    public function __construct(
        public string $id,
        public string $emailLookup,
        public string $email,
        public string $displayName,
        public string $passwordHash,
        public OperatorState $state,
        public ?string $secret,
        public ?string $pendingSecret,
        public ?DateTimeImmutable $pendingSecretCreatedAt,
        public ?int $lastCodeStep,
        public ?DateTimeImmutable $enrolledAt,
        public int $credentialEpoch,
        public ?DateTimeImmutable $temporaryPasswordExpiresAt,
        public ?DateTimeImmutable $lastSignedInAt,
    ) {}

    public function isEnrolled(): bool
    {
        return $this->secret !== null;
    }

    public function canSignIn(): bool
    {
        return $this->state !== OperatorState::Deactivated;
    }
}
