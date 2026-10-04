<?php

namespace App\Modules\Admin\Domain\Operators;

/**
 * Where an Operator Account is in its life (data-model, States).
 */
enum OperatorState: string
{
    case AwaitingPasswordChange = 'awaiting_password_change';
    case AwaitingEnrolment = 'awaiting_enrolment';
    case Active = 'active';
    case Deactivated = 'deactivated';
}
