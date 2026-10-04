<?php

namespace App\Modules\Admin\Application\Enrolment;

use App\Modules\Admin\Application\Dto\IssuedRecoveryCodes;
use App\Modules\Admin\Domain\Ports\RecoveryCodeHasher;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Domain\SecondFactor\RecoveryCode;

/**
 * Issues ten recovery codes, replacing any earlier set at once, and hands
 * the plain codes to the caller for their one display (FR-022, FR-024,
 * FR-026). Call inside the transaction of the act that issues them.
 */
final class IssueRecoveryCodes
{
    public const COUNT = 10;

    public function __construct(
        private readonly RecoveryCodeStore $store,
        private readonly RecoveryCodeHasher $hasher,
    ) {}

    public function handle(string $operatorId): IssuedRecoveryCodes
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $code = RecoveryCode::generate();
            $codes[$code->value] = $code;
        }

        $this->store->replaceSet($operatorId, array_values(array_map(
            fn (RecoveryCode $code): string => $this->hasher->hash($code->value),
            $codes,
        )));

        return new IssuedRecoveryCodes(array_values(array_map(fn (RecoveryCode $code): string => $code->display(), $codes)));
    }
}
