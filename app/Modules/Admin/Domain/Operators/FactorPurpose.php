<?php

namespace App\Modules\Admin\Domain\Operators;

/**
 * Why a second-factor proof is being checked; it names the `stage` recorded
 * when the proof fails.
 */
enum FactorPurpose: string
{
    case SignIn = 'sign_in';
    case Reconfirm = 'reconfirm';
    case Enrolment = 'enrolment';

    public function failureStage(): string
    {
        return match ($this) {
            self::SignIn => 'second_factor',
            self::Reconfirm => 'reconfirm',
            self::Enrolment => 'enrolment',
        };
    }
}
