<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\ExportRequestedDetail;
use App\Modules\Identity\Application\Jobs\GenerateExportJob;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Export\ExportRecord;
use App\Modules\Identity\Domain\Export\ExportScope;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Refusals\ExportInProgress;
use App\Modules\Identity\Domain\Refusals\ExportNotFound;
use App\Modules\Identity\Domain\Refusals\TooManyAttempts;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Application\WalletQueryService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Accepts a request for a copy (spec 022, FR-008 to FR-010, FR-017,
 * FR-018). Not gated by plan, flag or verification, and allowed in the
 * deletion grace period. In order: a wallet the person is not an active
 * member of is not found; an export already queued or running is refused
 * with itself as `current`, uncounted and unrecorded; five accepted
 * requests in 24 hours exhaust the budget; otherwise the export is queued,
 * one `export_requested` entry written in the same unit of work, and the
 * job dispatched after commit.
 */
final class RequestExport
{
    public function __construct(
        private readonly ExportRepository $exports,
        private readonly MembershipQueryService $memberships,
        private readonly WalletQueryService $wallets,
        private readonly ExportQuery $query,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  Closure(ExportView): array<string, mixed>  $present  how a read renders an export, for a refusal's `current`
     *
     * @throws ExportNotFound|ExportInProgress|TooManyAttempts
     */
    public function handle(string $userId, ExportScope $scope, ?string $walletId, Closure $present): ExportView
    {
        if ($scope === ExportScope::Wallet && ($walletId === null || $this->memberships->roleOf($userId, $walletId) === null || $this->wallets->liveName($walletId) === null)) {
            throw new ExportNotFound;
        }

        $this->refuseIfActive($userId, $present);

        $key = self::budgetKey($userId);
        $limit = (int) config('identity.export_daily_limit');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new TooManyAttempts(RateLimiter::availableIn($key));
        }

        try {
            $record = DB::transaction(function () use ($userId, $scope, $walletId): ExportRecord {
                $record = $this->exports->insertQueued((string) Str::uuid7(), $userId, $scope, $scope === ExportScope::Wallet ? $walletId : null, now()->toImmutable());
                $this->audit->record(AuditRecord::byUser(AuditEvent::ExportRequested, $userId, SubjectType::User, $userId, new ExportRequestedDetail($scope)));

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            $this->refuseIfActive($userId, $present);

            throw new RuntimeException('The export slot was taken and then freed.');
        }

        RateLimiter::hit($key, 86400);
        GenerateExportJob::dispatch($record->id)->afterCommit();

        return $this->query->present($record);
    }

    public static function budgetKey(string $userId): string
    {
        return 'identity-export:'.$userId;
    }

    /**
     * @param  Closure(ExportView): array<string, mixed>  $present
     */
    private function refuseIfActive(string $userId, Closure $present): void
    {
        $active = $this->exports->activeFor($userId);

        if ($active !== null) {
            throw new ExportInProgress($active, $present($this->query->present($active)));
        }
    }
}
