<?php

namespace App\Modules\Identity\Application\Dto;

use App\Modules\Identity\Domain\Sessions\SessionRecord;

/**
 * One session as its owner sees it: the device and the times, and nothing
 * further (security baseline, What a person sees and controls).
 */
final readonly class DeviceSessionView
{
    public function __construct(
        public SessionRecord $session,
        public bool $current,
    ) {}
}
