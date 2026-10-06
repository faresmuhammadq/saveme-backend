<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Budgeting\Domain\Events\BudgetExceeded;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A budget went strictly over its limit in a period: its owner hears of it
 * once, keyed by budget and period start (spec 023, FR-026). Budgeting holds
 * the "crossed once" record; this only delivers.
 */
final class NotifyBudgetExceeded implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(BudgetExceeded $event): void
    {
        $this->notifications->create(new NotificationDraft(
            $event->userId,
            MessageType::BudgetExceeded,
            'budget_exceeded:'.$event->budgetId.':'.$event->periodStart,
            ['budget_id' => $event->budgetId],
        ));
    }
}
