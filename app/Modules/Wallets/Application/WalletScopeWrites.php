<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How another module writes a record into a wallet's scope under that
 * wallet's lock (spec 016, R-10; ADR-0008 rules 3-5, spec 010 FR-012).
 *
 * within() opens a transaction (a savepoint inside a caller's), locks the
 * wallet row FOR UPDATE, runs the work, and, when the work stamped anything,
 * gives the wallet its final `change_seq` without moving its `version`.
 * Calls for the same wallet nest: the inner one joins the outer scope and the
 * outer one stamps the wallet last, so a membership write inside an
 * acceptance shares one scope with the invitation it closes.
 *
 * LOCK ORDER (ADR-0017 rule 5): wallet row, then invitation rows, then user
 * rows ascending, then membership rows.
 */
final class WalletScopeWrites
{
    /** @var array<string, ScopeWrite> */
    private array $open = [];

    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly ScopeSequence $sequence,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(ScopeWrite): T  $work
     * @return T
     *
     * @throws WalletNotFound when the wallet does not exist
     * @throws Throwable whatever the work throws; the transaction rolls back
     */
    public function within(string $walletId, Closure $work): mixed
    {
        if (isset($this->open[$walletId])) {
            return $work($this->open[$walletId]);
        }

        return DB::transaction(function () use ($walletId, $work): mixed {
            $wallet = $this->wallets->findForUpdate($walletId) ?? throw new WalletNotFound;
            $write = new ScopeWrite($wallet, $this->sequence);
            $this->open[$walletId] = $write;

            try {
                $result = $work($write);
            } finally {
                unset($this->open[$walletId]);
            }

            if ($write->hasStamped()) {
                $this->sequence->nextFor($walletId);
            }

            return $result;
        });
    }
}
