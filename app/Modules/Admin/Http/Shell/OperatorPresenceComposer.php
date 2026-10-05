<?php

namespace App\Modules\Admin\Http\Shell;

use App\Modules\Admin\Application\Operators\OperatorDirectory;
use App\Modules\Admin\Application\Reenrolment\ReenrolmentQuery;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Navigation\NavigationEntry;
use App\Modules\Admin\Http\Navigation\NavigationRegistry;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use Illuminate\Contracts\View\View;
use Illuminate\View\ComponentAttributeBag;

/**
 * Binds the shell to the signed-in operator without editing the shell (spec
 * 018, R-17; spec 006 FR-051): fills the operator block and the sign-out
 * form when the caller passed neither, and adds the operator's own sections
 * to the sidebar. Does nothing when nobody is signed in, so the gallery keeps
 * its sample operator and the shell renders as before.
 */
final class OperatorPresenceComposer
{
    public function __construct(
        private readonly OperatorSession $session,
        private readonly OperatorDirectory $directory,
        private readonly NavigationRegistry $navigation,
    ) {}

    public function compose(View $view): void
    {
        if ($this->session->qualifies() !== null) {
            return;
        }

        $operatorId = $this->session->operatorId();
        $operator = $operatorId === null ? null : $this->directory->view($operatorId);

        if ($operator === null) {
            return;
        }

        $attributes = $view->getData()['attributes'] ?? null;
        $passed = $attributes instanceof ComponentAttributeBag ? $attributes : new ComponentAttributeBag;

        if ($passed->get('operator') === null && $passed->get('signOutUrl', $passed->get('sign-out-url')) === null) {
            $view->with([
                'operator' => new OperatorPresence(
                    $operator->displayName,
                    (string) (grapheme_substr($operator->displayName, 0, 1) ?: '?'),
                    'admin::shell.operator.two_factor_on',
                ),
                'signOutUrl' => route('admin.sign-out'),
            ]);
        }

        $this->registerNavigation();
    }

    private function registerNavigation(): void
    {
        foreach ($this->workScreens() as $entry) {
            if (! $this->navigation->has($entry->key)) {
                $this->navigation->add($entry);
            }
        }

        if (! $this->navigation->has('security')) {
            $this->navigation->add(new NavigationEntry(
                key: 'security',
                group: 'configure',
                labelKey: 'admin::shell.nav.security',
                icon: 'lock',
                route: 'admin.security',
                order: 90,
            ));
        }

        if (! $this->navigation->has('reenrolment-requests')) {
            $this->navigation->add(new NavigationEntry(
                key: 'reenrolment-requests',
                group: 'configure',
                labelKey: 'admin::shell.nav.reenrolment_requests',
                icon: 'key',
                route: 'admin.reenrolment-requests',
                order: 91,
                badge: function (): ?int {
                    $viewer = app(OperatorSession::class)->operatorId();

                    return $viewer === null ? null : app(ReenrolmentQuery::class)->waitingCount($viewer);
                },
            ));
        }
    }

    /**
     * The operators' working screens (spec 021, R-1). Icons are the registry's
     * existing glyphs for them: claims, users, plans, audit.
     *
     * @return list<NavigationEntry>
     */
    private function workScreens(): array
    {
        return [
            new NavigationEntry(
                key: 'claims',
                group: 'operate',
                labelKey: 'admin::shell.nav.claims',
                icon: 'claims',
                route: 'admin.claims',
                order: 20,
                badge: fn (): ?int => app(ClaimQuery::class)->awaitingSummary()['count'] ?: null,
            ),
            new NavigationEntry(key: 'accounts', group: 'operate', labelKey: 'admin::shell.nav.accounts', icon: 'users', route: 'admin.accounts', order: 30),
            new NavigationEntry(key: 'plans', group: 'configure', labelKey: 'admin::shell.nav.plans', icon: 'plans', route: 'admin.plans', order: 10),
            new NavigationEntry(key: 'audit', group: 'configure', labelKey: 'admin::shell.nav.audit', icon: 'audit', route: 'admin.audit', order: 20),
        ];
    }
}
