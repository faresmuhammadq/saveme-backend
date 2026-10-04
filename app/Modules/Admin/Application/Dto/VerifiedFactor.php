<?php

namespace App\Modules\Admin\Application\Dto;

final readonly class VerifiedFactor
{
    /**
     * @param  'code'|'recovery_code'  $kind
     */
    public function __construct(public string $kind, public int $codesLeft) {}
}
