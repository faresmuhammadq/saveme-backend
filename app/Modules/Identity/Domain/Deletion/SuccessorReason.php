<?php

namespace App\Modules\Identity\Domain\Deletion;

/**
 * Why a member was picked to own a departing person's shared wallet.
 */
enum SuccessorReason: string
{
    case OnlyMember = 'only_member';
    case Chosen = 'chosen';
    case OldestEditor = 'oldest_editor';
    case OldestViewer = 'oldest_viewer';
}
