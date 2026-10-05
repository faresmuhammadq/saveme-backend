<?php

namespace App\Modules\Admin\Application\Reenrolment;

use App\Modules\Admin\Application\Dto\ReenrolmentRequestView;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;

/**
 * Open re-enrolment requests for the requests page and the navigation badge.
 * Display name, instants and approval count only (FR-034).
 */
final class ReenrolmentQuery
{
    public function __construct(
        private readonly ReenrolmentStore $requests,
        private readonly OperatorRepository $operators,
    ) {}

    /**
     * @return list<ReenrolmentRequestView>
     */
    public function openFor(string $viewerId): array
    {
        $now = now()->toDateTimeImmutable();
        $views = [];

        foreach ($this->requests->listOpen() as $request) {
            $requester = $this->operators->find($request->operatorId);

            $views[] = new ReenrolmentRequestView(
                id: $request->id,
                requesterName: $requester->displayName ?? '',
                openedAt: $request->openedAt->format('Y-m-d\TH:i:s.uP'),
                expiresAt: $request->expiresAt->format('Y-m-d\TH:i:s.uP'),
                approvals: $this->requests->approvalCount($request->id),
                isOwn: $request->operatorId === $viewerId,
                viewerApproved: $this->requests->hasApproval($request->id, $viewerId),
                expired: $request->expiresAt <= $now,
            );
        }

        return $views;
    }

    /**
     * Open requests that have not expired, whoever opened them: the
     * overview's needs-attention figure (spec 021, FR-012).
     */
    public function openCount(): int
    {
        $now = now()->toDateTimeImmutable();

        return count(array_filter(
            $this->requests->listOpen(),
            fn ($request): bool => $request->expiresAt > $now,
        ));
    }

    /**
     * Waiting requests the viewer could still act on, for the badge.
     */
    public function waitingCount(string $viewerId): int
    {
        return count(array_filter(
            $this->openFor($viewerId),
            fn (ReenrolmentRequestView $view): bool => ! $view->isOwn && ! $view->expired,
        ));
    }
}
