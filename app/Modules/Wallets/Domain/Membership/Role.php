<?php

namespace App\Modules\Wallets\Domain\Membership;

/**
 * A person's role in one wallet (glossary, Wallet Member).
 */
enum Role: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
