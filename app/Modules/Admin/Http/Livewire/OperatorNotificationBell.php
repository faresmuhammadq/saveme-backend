<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\OperatorNotificationQueryService;
use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The top bar's notification bell (spec 007, R-14). Reads through Admin's
 * query service and holds only scalars (P-18 rule 5). Mounted in the shell's
 * `notifications` slot by operator sign-in (D2); until then no /admin page
 * renders it, and the gallery shows its markup with sample data.
 */
class OperatorNotificationBell extends Component
{
    public int $unread = 0;

    /** @var list<array{id: string, kind: string, subject_user_id: string, occurred_at: string, title_key: string, body_key: string, params: array<string, scalar|null>}> */
    public array $items = [];

    public function mount(OperatorNotificationQueryService $notifications): void
    {
        $this->unread = $notifications->unreadCount();
        $this->items = array_map(fn (OperatorNotificationView $view): array => $view->toArray(), $notifications->latest());
    }

    public function render(): View
    {
        return view('admin::livewire.operator-notification-bell');
    }
}
