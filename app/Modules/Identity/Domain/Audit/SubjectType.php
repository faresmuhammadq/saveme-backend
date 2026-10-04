<?php

namespace App\Modules\Identity\Domain\Audit;

/**
 * What an audit entry is about, referenced by identifier only.
 */
enum SubjectType: string
{
    case User = 'user';
    case Wallet = 'wallet';
    case Session = 'session';
    case Subscription = 'subscription';
    case Operator = 'operator';
    case PaymentClaim = 'payment_claim';
}
