<?php

namespace App\Modules\Identity\Domain\Export;

/**
 * Why an Export failed. A new request may be made at once after any of them.
 */
enum ExportFailure: string
{
    case GenerationFailed = 'generation_failed';
    case WalletUnavailable = 'wallet_unavailable';
    case TimedOut = 'timed_out';
}
