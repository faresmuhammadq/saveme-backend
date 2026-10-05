<?php

namespace App\Modules\Admin\Application\Audit;

use App\Modules\Identity\Application\Audit\AuditPage;

/**
 * A page of the audit log with the display names of the operators in it.
 */
final readonly class AuditReadView
{
    /**
     * @param  array<string, string>  $operatorNames  operator id => display name
     */
    public function __construct(public AuditPage $page, public array $operatorNames) {}
}
