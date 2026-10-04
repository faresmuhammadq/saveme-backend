<?php

namespace App\Modules\Admin\Domain\Operators;

/**
 * The stage of a half-finished sign-in, kept in the session and never in the
 * guard (spec 018, R-6). `codes_shown` follows enrolment: the recovery codes
 * were delivered and the saved-confirmation is awaited.
 */
enum SignInStage: string
{
    case SecondFactor = 'second_factor';
    case PasswordChange = 'password_change';
    case Enrolment = 'enrolment';
    case CodesShown = 'codes_shown';
}
