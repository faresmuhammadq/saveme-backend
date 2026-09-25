{{--
    Sidebar (spec 006, FR-006): brand, grouped navigation, operator block.
    navigation: the output of NavigationRegistry::groups().
--}}
@props(['navigation', 'operator' => null, 'signOutUrl' => null])

<nav aria-label="{{ __('admin::shell.primary') }}" class="sticky top-0 flex h-dvh w-62 shrink-0 flex-col border-e border-line bg-surface px-3.5 py-4.5">
    <div class="flex items-center gap-2.5 px-2 pb-4.5">
        <svg viewBox="0 0 32 32" class="size-7.5 shrink-0 rtl:-scale-x-100" aria-hidden="true">
            <rect width="32" height="32" rx="10" class="fill-accent" />
            <circle cx="16" cy="16" r="8" fill="none" stroke-width="3.2" class="stroke-on-accent opacity-35" />
            <path d="M16 8a8 8 0 0 1 7.6 10.5" fill="none" stroke-width="3.2" stroke-linecap="round" class="stroke-on-accent" />
        </svg>
        <div class="min-w-0">
            <div class="truncate text-lead font-extrabold text-ink">{{ config('app.name') }}</div>
            <div class="text-caption font-semibold text-ink-3">{{ __('admin::shell.back_office') }}</div>
        </div>
    </div>

    <div class="flex flex-1 flex-col gap-4 overflow-y-auto">
        @forelse ($navigation as $group)
            <div class="flex flex-col gap-0.5">
                <div class="px-2.5 pb-1.5 text-overline font-bold tracking-widest text-ink-3 uppercase">{{ __($group['group']->labelKey) }}</div>
                @foreach ($group['entries'] as $item)
                    <x-admin::shell.nav-item
                        :href="\Illuminate\Support\Facades\Route::has($item['entry']->route) ? route($item['entry']->route) : '#'"
                        :icon="$item['entry']->icon"
                        :label="__($item['entry']->labelKey)"
                        :current="$item['current']"
                        :badge="$item['badge']"
                    />
                @endforeach
            </div>
        @empty
            <p class="px-2.5 text-label text-ink-3">{{ __('admin::shell.navigation_empty') }}</p>
        @endforelse
    </div>

    <div class="flex flex-col gap-3 border-t border-line px-1.5 pt-3">
        @if ($operator)
            <div class="flex items-center gap-2.5">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-positive text-label font-extrabold text-on-accent" aria-hidden="true">{{ $operator->initial }}</div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-label font-bold text-ink">{{ $operator->displayName }}</div>
                    <div class="truncate text-caption text-ink-3">{{ __($operator->statusKey) }}</div>
                </div>
                @if ($signOutUrl)
                    <form method="POST" action="{{ $signOutUrl }}">
                        @csrf
                        <x-admin::icon-button type="submit" icon="sign-out" :label="__('admin::shell.sign_out')" size="sm" />
                    </form>
                @endif
            </div>
        @endif
        <livewire:admin::locale-switcher />
    </div>
</nav>
