<?php

namespace App\Modules\Identity\Application\Dto;

use App\Modules\Billing\Application\Entitlements\EntitlementSummary;

final readonly class SignInResult
{
    public function __construct(
        public string $credential,
        public DeviceSessionView $session,
        public AccountView $account,
        public EntitlementSummary $entitlements,
        public bool $deletionCancelled,
    ) {}
}
