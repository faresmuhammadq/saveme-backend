<?php

namespace App\Modules\Identity\Domain\Export;

/**
 * An Export's lifecycle: queued → running → ready → expired, or failed.
 */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    /**
     * Whether the export still occupies the person's single active slot.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
