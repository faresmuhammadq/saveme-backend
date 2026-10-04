<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Dto\OperatorView;
use App\Modules\Admin\Application\Operators\OperatorDirectory;
use App\Modules\Admin\Http\Auth\OperatorSession;
use Livewire\Component;

/**
 * The base of every back-office Livewire component (constitution P-18 rules
 * 4 and 7; spec 018, R-8). `boot()` runs on every request a component
 * handles, the first render and every update through the shared endpoint, and
 * refuses with 401 when the session is not a fully signed-in operator's. The
 * arch gate fails any component that does not extend it, apart from the
 * language switch and the local gallery, which read no data.
 */
abstract class BackOfficeComponent extends Component
{
    public function boot(): void
    {
        abort_unless(app(OperatorSession::class)->qualifies() === null, 401);
    }

    protected function operatorId(): string
    {
        $id = app(OperatorSession::class)->operatorId();
        abort_if($id === null, 401);

        return $id;
    }

    protected function operator(): OperatorView
    {
        $view = app(OperatorDirectory::class)->view($this->operatorId());
        abort_if($view === null, 401);

        return $view;
    }
}
