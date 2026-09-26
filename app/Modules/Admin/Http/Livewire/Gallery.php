<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use App\Modules\Admin\Http\Icons\IconRegistry;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresReason;
use App\Modules\Admin\Http\Navigation\NavigationEntry;
use App\Modules\Admin\Http\Navigation\NavigationGroup;
use App\Modules\Admin\Http\Navigation\NavigationRegistry;
use App\Modules\Admin\Http\Shell\OperatorPresence;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The primitives gallery (spec 006, US4, FR-040): every primitive in every
 * state, in the shell, with sample text only. Registered in the local
 * environment alone (FR-041). It reads no application data.
 */
class Gallery extends Component
{
    use RequiresReason;

    public function startSampleApproval(): void
    {
        $this->requireReason('gallery.approve', 'sampleApprove');
    }

    protected function sampleApprove(AuditReason $reason): void
    {
        $this->dispatch('admin-toast', tone: 'success', message: __('admin::gallery.toasts.approved', ['reason' => $reason->value()]));
    }

    public function render(): View
    {
        return view('admin::livewire.gallery', [
            'icons' => app(IconRegistry::class)->names(),
            // Sample notifications for the bell's markup (spec 007): never the query service.
            'sampleNotifications' => [
                (new OperatorNotificationView(
                    id: '0192f7a0-0000-7000-8000-000000000001',
                    kind: 'deletion_cancelled_by_sign_in',
                    subjectUserId: '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70',
                    occurredAt: '2026-09-26T08:30:00.000000Z',
                    titleKey: 'admin::notifications.kinds.deletion_cancelled_by_sign_in.title',
                    bodyKey: 'admin::notifications.kinds.deletion_cancelled_by_sign_in.body',
                ))->toArray(),
            ],
            'planOptions' => [
                ['value' => 'free', 'label' => __('admin::gallery.plans.free'), 'meta' => 'EGP 0'],
                ['value' => 'plus_monthly', 'label' => __('admin::gallery.plans.plus_monthly'), 'meta' => 'EGP 149'],
                ['value' => 'plus_yearly', 'label' => __('admin::gallery.plans.plus_yearly'), 'meta' => 'EGP 1,290'],
                ['value' => 'lifetime', 'label' => __('admin::gallery.plans.lifetime'), 'meta' => 'EGP 2,990'],
            ],
        ])->layout('admin::page', [
            'title' => __('admin::gallery.title'),
            'subtitle' => __('admin::gallery.subtitle'),
            'breadcrumbs' => [['label' => __('admin::gallery.breadcrumb_root'), 'href' => '#'], ['label' => __('admin::gallery.title')]],
            'operator' => new OperatorPresence(__('admin::gallery.sample_operator'), __('admin::gallery.sample_operator_initial'), 'admin::shell.operator.two_factor_on'),
            'signOutUrl' => '#',
            'navigation' => $this->sampleNavigation(),
        ]);
    }

    /**
     * @return list<array{group: NavigationGroup, entries: list<array{entry: NavigationEntry, current: bool, badge: ?int}>}>
     */
    private function sampleNavigation(): array
    {
        $registry = new NavigationRegistry(app(IconRegistry::class));

        $registry->add(new NavigationEntry('overview', 'operate', 'admin::gallery.nav.overview', 'overview', 'admin.sample.overview', 10));
        $registry->add(new NavigationEntry('claims', 'operate', 'admin::gallery.nav.claims', 'claims', 'admin.gallery', 20, fn (): int => 38));
        $registry->add(new NavigationEntry('users', 'operate', 'admin::gallery.nav.users', 'users', 'admin.sample.users', 30));
        $registry->add(new NavigationEntry('subscriptions', 'operate', 'admin::gallery.nav.subscriptions', 'refresh', 'admin.sample.subscriptions', 40));
        $registry->add(new NavigationEntry('plans', 'configure', 'admin::gallery.nav.plans', 'plans', 'admin.sample.plans', 10));
        $registry->add(new NavigationEntry('audit', 'configure', 'admin::gallery.nav.audit', 'audit', 'admin.sample.audit', 20));

        return $registry->groups('admin.gallery');
    }
}
