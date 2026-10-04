<?php

namespace App\Modules\Admin\Application\Dto;

/**
 * An open re-enrolment request as the requests page shows it: who asked,
 * when, how many approvals, and what the viewer may do. Nothing about the
 * requester's factor (FR-034).
 */
final readonly class ReenrolmentRequestView
{
    public function __construct(
        public string $id,
        public string $requesterName,
        public string $openedAt,
        public string $expiresAt,
        public int $approvals,
        public bool $isOwn,
        public bool $viewerApproved,
        public bool $expired,
    ) {}

    public function viewerMayAct(): bool
    {
        return ! $this->isOwn && ! $this->viewerApproved && ! $this->expired;
    }
}
