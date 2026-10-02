<?php

namespace App\Modules\Wallets\Application\Transactions;

use RuntimeException;

/**
 * Thrown inside the write's unit of work when the transaction's categories or
 * wallets changed between the unlocked read and the locks, so the unit of work
 * rolls back and starts again with the right lock set (spec 012, R-4).
 */
final class DriftedUnderLock extends RuntimeException {}
