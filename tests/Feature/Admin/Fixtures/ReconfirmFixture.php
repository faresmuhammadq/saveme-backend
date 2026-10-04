<?php

namespace Tests\Feature\Admin\Fixtures;

use App\Modules\Admin\Http\Livewire\BackOfficeComponent;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use Illuminate\Contracts\View\View;

/**
 * A screen with one sensitive act, as a later feature would write it.
 */
class ReconfirmFixture extends BackOfficeComponent
{
    use RequiresRecentFactor;

    public int $runs = 0;

    public function act(): void
    {
        $this->requireRecentFactor('doIt');
    }

    protected function doIt(): void
    {
        $this->runs++;
    }

    public function render(): View
    {
        return view('fixtures::guarded-fixture', ['count' => $this->runs]);
    }
}
