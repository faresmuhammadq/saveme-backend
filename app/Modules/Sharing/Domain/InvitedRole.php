<?php

namespace App\Modules\Sharing\Domain;

/**
 * The role an invitee joins with. Never `owner`: ownership moves only by
 * transfer (spec 010 W-05).
 */
enum InvitedRole: string
{
    case Editor = 'editor';
    case Viewer = 'viewer';
}
