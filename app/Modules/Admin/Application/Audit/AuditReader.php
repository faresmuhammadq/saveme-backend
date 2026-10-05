<?php

namespace App\Modules\Admin\Application\Audit;

use App\Modules\Admin\Application\Operators\OperatorDirectory;
use App\Modules\Identity\Application\Audit\AuditCursor;
use App\Modules\Identity\Application\Audit\AuditFilter;
use App\Modules\Identity\Application\Audit\AuditQuery;
use Illuminate\Support\Facades\DB;

/**
 * The only way a screen reads the audit log (spec 021, FR-044, FR-045; R-8):
 * one `audit_read` entry, then the query, in one transaction, so a failed
 * query leaves no entry and a written entry always had its query.
 */
final class AuditReader
{
    public const PAGE_FIRST = 'first';

    public const PAGE_OLDER = 'older';

    public const PAGE_NEWER = 'newer';

    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly AuditQuery $query,
        private readonly OperatorDirectory $operators,
    ) {}

    /**
     * @param  string  $page  first | older | newer
     */
    public function read(string $operatorId, AuditFilter $filter, ?AuditCursor $cursor, string $page): AuditReadView
    {
        $direction = $page === self::PAGE_NEWER ? AuditQuery::NEWER : AuditQuery::OLDER;

        $result = DB::transaction(function () use ($operatorId, $filter, $cursor, $page, $direction) {
            $this->audit->auditRead($operatorId, $filter->toDetail() + ['page' => $page]);

            return $this->query->page($filter, $cursor, $direction);
        });

        $operatorIds = [];

        foreach ($result->rows as $row) {
            if ($row->actorType === 'operator' && $row->actorId !== null) {
                $operatorIds[$row->actorId] = $row->actorId;
            }
        }

        return new AuditReadView($result, $this->operators->displayNames(array_values($operatorIds)));
    }
}
