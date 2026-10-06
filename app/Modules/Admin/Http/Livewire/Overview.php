<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Overview\OverviewFigures;
use Illuminate\Contracts\View\View;

/**
 * The page an operator lands on after signing in (spec 018, R-18). A minimal
 * guarded page, since spec 021 (B12) the platform overview: counts only, with
 * the anonymity floor, and what needs attention. The low recovery-codes alert
 * stays first.
 */
class Overview extends BackOfficeComponent
{
    public const LOW_CODES = 3;

    public int $recoveryCodesLeft = 10;

    /** @var array<string, int|string> */
    public array $figures = [];

    public int $claimsAwaiting = 0;

    public ?string $oldestClaimAt = null;

    public int $reenrolmentsOpen = 0;

    /** @var array{created: int, sent: int, skipped: int, failed: int, pruned: int} */
    public array $push = ['created' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'pruned' => 0];

    public function mount(OverviewFigures $overview): void
    {
        $this->recoveryCodesLeft = $this->operator()->recoveryCodesLeft;

        $computed = $overview->compute();
        $this->figures = $computed['figures'];
        $this->claimsAwaiting = $computed['claims_awaiting'];
        $this->oldestClaimAt = $computed['oldest_claim_at'];
        $this->reenrolmentsOpen = $computed['reenrolments_open'];
        $this->push = $computed['push'];
    }

    public function render(): View
    {
        return view('admin::livewire.overview')
            ->layout('admin::page', ['title' => __('admin::overview.title'), 'subtitle' => __('admin::overview.subtitle')]);
    }
}
