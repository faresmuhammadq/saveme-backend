<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Dto\ReenrolmentRequestView;
use App\Modules\Admin\Application\Reenrolment\ApproveReenrolment;
use App\Modules\Admin\Application\Reenrolment\DeclineReenrolment;
use App\Modules\Admin\Application\Reenrolment\ReenrolmentQuery;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\Refusals\ReenrolmentRefused;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresReason;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use Illuminate\Contracts\View\View;

/**
 * Open re-enrolment requests, for other operators to approve or decline
 * (spec 018, US4; FR-031 to FR-034). Each act asks for a fresh second-factor
 * proof first, then for a reason (the two prompts compose). The requester's
 * own request and a request the viewer already approved offer no action, and
 * the services refuse a forged call all the same.
 */
class ReenrolmentRequests extends BackOfficeComponent
{
    use RequiresReason, RequiresRecentFactor;

    public function approve(string $requestId): void
    {
        $this->requireRecentFactor('askApprovalReason', [$requestId]);
    }

    public function decline(string $requestId): void
    {
        $this->requireRecentFactor('askDeclineReason', [$requestId]);
    }

    protected function askApprovalReason(string $requestId): void
    {
        $this->requireReason('reenrolment.approve', 'approveRequest', [$requestId]);
    }

    protected function askDeclineReason(string $requestId): void
    {
        $this->requireReason('reenrolment.decline', 'declineRequest', [$requestId]);
    }

    protected function approveRequest(string $requestId, AuditReason $reason): void
    {
        try {
            $completed = app(ApproveReenrolment::class)->handle($this->operatorId(), $requestId, $reason);
        } catch (ReenrolmentRefused $refused) {
            $this->refused($refused);

            return;
        }

        $this->dispatch('admin-toast', tone: 'success', message: __($completed ? 'admin::security.toasts.reenrolment_completed' : 'admin::security.toasts.reenrolment_approved'));
    }

    protected function declineRequest(string $requestId, AuditReason $reason): void
    {
        try {
            app(DeclineReenrolment::class)->handle($this->operatorId(), $requestId, $reason);
        } catch (ReenrolmentRefused $refused) {
            $this->refused($refused);

            return;
        }

        $this->dispatch('admin-toast', tone: 'success', message: __('admin::security.toasts.reenrolment_declined'));
    }

    private function refused(ReenrolmentRefused $refused): void
    {
        $this->dispatch('admin-toast', tone: 'error', message: __('admin::security.requests.refused.'.$refused->reason));
    }

    public function render(): View
    {
        return view('admin::livewire.reenrolment-requests', [
            'requests' => array_map(
                fn (ReenrolmentRequestView $view): array => [
                    'id' => $view->id,
                    'requester' => $view->requesterName,
                    'opened_at' => $view->openedAt,
                    'expires_at' => $view->expiresAt,
                    'approvals' => $view->approvals,
                    'expired' => $view->expired,
                    'may_act' => $view->viewerMayAct(),
                ],
                app(ReenrolmentQuery::class)->openFor($this->operatorId()),
            ),
        ])->layout('admin::page', ['title' => __('admin::security.requests.title')]);
    }
}
