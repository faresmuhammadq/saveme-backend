<?php

namespace App\Modules\Sharing\Domain;

/**
 * How an invitation reaches the invitee: a mail to one address, or a link
 * the inviter shares themselves (spec 016, R-2).
 */
enum InvitationChannel: string
{
    case Email = 'email';
    case Link = 'link';
}
