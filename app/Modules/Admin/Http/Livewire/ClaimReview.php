<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Claims\ClaimReading;
use App\Modules\Admin\Application\Operators\OperatorDirectory;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresReason;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use App\Modules\Billing\Application\Claims\ClaimDetail;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Application\Claims\ClaimReceipts;
use App\Modules\Billing\Application\Claims\ClaimReviewState;
use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Application\Claims\ClaimSummary;
use App\Modules\Billing\Domain\Claims\RejectReason;
use App\Modules\Billing\Domain\Refusals\ClaimNotReviewable;
use App\Modules\Billing\Domain\Refusals\CodeNotRevocable;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\InvalidRejectReason;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\ReferenceAlreadyApproved;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * One claim to review (spec 021, US1; FR-016 to FR-019). Opening it is the
 * recorded read of the address (once, in `mount()`, R-3); an action only
 * refreshes the review facts through the address-free `reviewState`, so no
 * second entry is written. Approve, reject and revoke each ask for a fresh
 * second-factor proof first and call `ClaimService` with the signed-in
 * operator's id; the protected `apply*` methods are the only way in.
 */
class ClaimReview extends BackOfficeComponent
{
    use RequiresReason, RequiresRecentFactor;

    private const INSTANT = 'Y-m-d\TH:i:s.uP';

    #[Locked]
    public string $claimId = '';

    /** @var array<string, mixed> the claim as opened: payment, payer, receipt and same-reference rows */
    public array $facts = [];

    /** @var array<string, mixed> the review facts, refreshed after an action */
    public array $review = [];

    public string $rejectReason = '';

    public string $rejectNote = '';

    public string $receiptUrl = '';

    public string $receiptExpiresAt = '';

    public function mount(string $claim): void
    {
        $detail = app(ClaimReading::class)->open($this->operatorId(), $claim);

        abort_if($detail === null, 404);

        $this->claimId = $claim;
        $this->facts = $this->claimFacts($detail);
        $this->review = $this->reviewFacts($detail->summary->status, $detail->summary->expiresAt, $detail->reviewedBy, $detail->reviewedAt, $detail->rejectReason, $detail->reviewNote, $detail->codeStatus, $detail->codeExpiresAt);
    }

    public function getReceiptLink(): void
    {
        $url = $this->facts['has_receipt'] ? app(ClaimReceipts::class)->temporaryUrl($this->claimId) : null;

        $this->receiptUrl = $url ?? '';
        $this->receiptExpiresAt = $url === null ? '' : now()->addMinutes((int) config('billing.instapay.receipt_link_minutes'))->format(self::INSTANT);
    }

    public function confirmApprove(): void
    {
        $this->dispatch('admin-dialog-close', name: 'approve-claim');
        $this->requireRecentFactor('applyApprove', [$this->claimId]);
    }

    public function confirmReject(): void
    {
        $this->resetErrorBag();

        if (RejectReason::tryFrom($this->rejectReason) === null) {
            $this->addError('rejectReason', __('admin::claims.errors.invalid_reject_reason'));

            return;
        }

        if (mb_strlen(trim($this->rejectNote)) > 500) {
            $this->addError('rejectNote', __('admin::claims.errors.note_too_long'));

            return;
        }

        $this->dispatch('admin-dialog-close', name: 'reject-claim');
        $this->requireRecentFactor('applyReject', [$this->claimId, $this->rejectReason, trim($this->rejectNote)]);
    }

    public function revokeCode(): void
    {
        $this->requireReason('claim.revoke', 'askRevokeFactor', [$this->claimId]);
    }

    protected function askRevokeFactor(string $claimId, AuditReason $reason): void
    {
        $this->requireRecentFactor('applyRevoke', [$claimId, $reason->value()]);
    }

    protected function applyApprove(string $claimId): void
    {
        $this->run(fn () => app(ClaimService::class)->approve($this->operatorId(), $claimId), 'approved');
    }

    protected function applyReject(string $claimId, string $reason, string $note): void
    {
        $this->run(fn () => app(ClaimService::class)->reject($this->operatorId(), $claimId, $reason, $note === '' ? null : $note), 'rejected');

        $this->reset('rejectReason', 'rejectNote');
    }

    protected function applyRevoke(string $claimId, string $reason): void
    {
        $this->run(fn () => app(ClaimService::class)->revokeCode($this->operatorId(), $claimId, $reason), 'revoked');
    }

    /**
     * Runs a claim command, maps its refusals to messages, and refreshes the
     * review facts (never the address: no second recorded read).
     */
    private function run(callable $command, string $done): void
    {
        $error = null;

        try {
            $command();
        } catch (ClaimNotReviewable) {
            $error = 'not_reviewable';
        } catch (ReferenceAlreadyApproved) {
            $error = 'reference_approved';
        } catch (PlanNotGrantable) {
            $error = 'plan_not_grantable';
        } catch (CodeNotRevocable) {
            $error = 'code_not_revocable';
        } catch (InvalidRejectReason $invalid) {
            $error = ($invalid->fieldErrors[0]->pointer ?? '') === '/note' ? 'note_too_long' : 'invalid_reject_reason';
        } catch (InvalidReason) {
            $error = 'invalid_reason';
        }

        $this->refreshReview();

        $error === null
            ? $this->dispatch('admin-toast', tone: 'success', message: __('admin::claims.messages.'.$done))
            : $this->dispatch('admin-toast', tone: 'error', message: __('admin::claims.errors.'.$error));
    }

    private function refreshReview(): void
    {
        $state = app(ClaimQuery::class)->reviewState($this->claimId);

        if ($state instanceof ClaimReviewState) {
            $this->review = $this->reviewFacts($state->status, $state->expiresAt, $state->reviewedBy, $state->reviewedAt, $state->rejectReason, $state->reviewNote, $state->codeStatus, $state->codeExpiresAt);
            $this->facts['erased'] = $state->erasedAt !== null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function claimFacts(ClaimDetail $detail): array
    {
        $summary = $detail->summary;
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return [
            'reference' => $summary->reference,
            'plan' => $summary->planName[$locale] ?? '',
            'amount_minor' => $summary->amountMinor,
            'currency' => $summary->currency,
            'matches_price' => $detail->matchesCurrentPrice,
            'created_at' => $summary->createdAt->format(self::INSTANT),
            'language' => $detail->locale,
            'address' => $detail->address,
            'account_exists' => $detail->accountExists,
            'erased' => $detail->erasedAt !== null,
            'has_receipt' => $detail->hasReceipt,
            'duplicates' => array_map(fn (ClaimSummary $other): array => [
                'id' => $other->id,
                'reference' => $other->reference,
                'status' => $other->status,
                'created_at' => $other->createdAt->format(self::INSTANT),
            ], $detail->duplicates),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewFacts(string $status, DateTimeImmutable $expiresAt, ?string $reviewedBy, ?DateTimeImmutable $reviewedAt, ?string $rejectReason, ?string $note, ?string $codeStatus, ?DateTimeImmutable $codeExpiresAt): array
    {
        return [
            'status' => $status,
            'expires_at' => $expiresAt->format(self::INSTANT),
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt?->format(self::INSTANT),
            'reject_reason' => $rejectReason,
            'note' => $note,
            'code_status' => $codeStatus,
            'code_expires_at' => $codeExpiresAt?->format(self::INSTANT),
        ];
    }

    public function render(): View
    {
        $review = $this->review;
        $reviewer = $review['reviewed_by'] === null ? null : (app(OperatorDirectory::class)->displayNames([$review['reviewed_by']])[$review['reviewed_by']] ?? null);
        $open = $review['status'] === 'submitted' && new DateTimeImmutable($review['expires_at']) > now();

        return view('admin::livewire.claim-review', [
            'reviewer' => $reviewer,
            'canDecide' => $open,
            'canRevoke' => $review['status'] === 'approved' && $review['code_status'] === 'issued',
        ])->layout('admin::page', [
            'title' => __('admin::claims.review.title', ['reference' => $this->facts['reference']]),
            'breadcrumbs' => [
                ['label' => __('admin::claims.title'), 'href' => route('admin.claims')],
                ['label' => $this->facts['reference']],
            ],
        ]);
    }
}
