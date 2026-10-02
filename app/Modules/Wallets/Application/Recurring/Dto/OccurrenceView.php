<?php

namespace App\Modules\Wallets\Application\Recurring\Dto;

use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;

/**
 * An occurrence as a read presents it.
 */
final readonly class OccurrenceView
{
    public function __construct(public OccurrenceRecord $occurrence) {}
}
