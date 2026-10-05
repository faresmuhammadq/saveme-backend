<?php

namespace App\Modules\Identity\Domain\Export;

/**
 * What an Export covers: everything the person entered and can see, or one
 * wallet of it (glossary: Export).
 */
enum ExportScope: string
{
    case Account = 'account';
    case Wallet = 'wallet';
}
