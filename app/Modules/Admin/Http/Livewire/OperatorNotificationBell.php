<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Notifications\NotificationReadMarks;
use App\Modules\Admin\Application\OperatorNotificationQueryService;
use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use Illuminate\Contracts\View\View;

/**
 * The top bar's notification bell (spec 007, R-14). Reads through Admin's
 * query service and holds only scalars (P-18 rule 5). Mounted in the shell's
 * `notifications` slot by the back-office page layout (spec 018); the gallery
 * shows its markup with sample data. Read marks are per operator (spec 021,
 * US6). Like every back-office component it
 * refuses an update when the session no longer qualifies.
 */
class OperatorNotificationBell extends BackOfficeComponent
{
    public int $unread = 0;

    /** @var list<array{id: string, kind: string, subject_user_id: string, occurred_at: string, title_key: string, body_key: string, params: array<string, scalar|null>, read: bool}> */
    public array $items = [];

    public function mount(OperatorNotificationQueryService $notifications): void
    {
        $this->load($notifications);
    }

    /**
     * Marks the item read for this operator only, then follows it to the
     * person's account page: an ordinary, recorded, budgeted opening (FR-052).
     */
    public function open(string $id, NotificationReadMarks $marks): void
    {
        $item = collect($this->items)->firstWhere('id', $id);

        if ($item === null) {
            return;
        }

        $marks->markRead($this->operatorId(), $id);

        $this->redirectRoute('admin.accounts.show', ['user' => $item['subject_user_id']]);
    }

    public function markAllRead(NotificationReadMarks $marks, OperatorNotificationQueryService $notifications): void
    {
        $marks->markAllRead($this->operatorId());

        $this->load($notifications);
    }

    public function render(): View
    {
        return view('admin::livewire.operator-notification-bell');
    }

    private function load(OperatorNotificationQueryService $notifications): void
    {
        $this->unread = $notifications->unreadCountFor($this->operatorId());
        $this->items = array_map(fn (OperatorNotificationView $view): array => $view->toArray(), $notifications->latestFor($this->operatorId()));
    }
}
