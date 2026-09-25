<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The operator's language switch (spec 006, FR-037). Stores the choice for
 * the session and reloads the page, so direction flips everywhere at once.
 */
class LocaleSwitcher extends Component
{
    public string $locale = 'en';

    #[Locked]
    public string $returnTo = '/';

    public function mount(): void
    {
        $this->locale = app()->getLocale();
        $this->returnTo = request()->fullUrl();
    }

    public function switchTo(string $locale): void
    {
        Validator::make(['locale' => $locale], [
            'locale' => ['required', Rule::in(config('admin.supported_locales'))],
        ])->validate();

        session()->put(ResolveAdminLocale::SESSION_KEY, $locale);
        $this->locale = $locale;

        $this->redirect($this->returnTo);
    }

    public function render(): View
    {
        return view('admin::livewire.locale-switcher');
    }
}
