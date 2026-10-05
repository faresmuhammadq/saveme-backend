<?php

namespace App\Modules\Admin\Application\Audit;

use App\Modules\Identity\Application\Audit\Details\AuditDetail;

/**
 * The closed `detail` of an `audit_read` entry (spec 021, contracts/audit.md):
 * the page and the filters that were asked for. Members outside the closed
 * list are dropped, so nothing else can be passed in.
 */
final readonly class AuditReadDetail implements AuditDetail
{
    private const MEMBERS = ['page', 'from', 'to', 'event', 'actor_type', 'actor_id', 'subject_type', 'subject_id'];

    /**
     * @param  array<string, string>  $members
     */
    public function __construct(private array $members) {}

    public function toArray(): array
    {
        $detail = [];

        foreach (self::MEMBERS as $member) {
            $value = $this->members[$member] ?? null;

            if ($value !== null && $value !== '') {
                $detail[$member] = (string) $value;
            }
        }

        return $detail;
    }
}
