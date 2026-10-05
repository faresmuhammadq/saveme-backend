<?php

namespace App\Modules\Identity\Application\Export;

use RuntimeException;

/**
 * The wallet of a wallet export was deleted, or the person left it, between
 * the request and the run. The export fails without a retry.
 */
final class WalletUnavailable extends RuntimeException {}
