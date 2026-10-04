<?php

namespace Tests\Feature\Admin\Fixtures;

use App\Modules\Admin\Http\Livewire\BackOfficeComponent;
use Illuminate\Contracts\View\View;

/**
 * What a later feature's screen looks like: a back-office component on a
 * route in the operator group, with no sign-in code of its own.
 */
class GuardedFixture extends BackOfficeComponent
{
    public int $count = 0;

    public function bump(): void
    {
        $this->count++;
    }

    public function render(): View
    {
        return view('fixtures::guarded-fixture')->layout('admin::page', ['title' => 'Fixture']);
    }
}
