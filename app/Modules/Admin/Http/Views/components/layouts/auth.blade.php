{{--
    The layout of every screen before sign-in is complete (spec 018,
    contracts/routes-and-screens.md): a brand panel at the start, a 420 px form
    column, a two-segment step indicator and the language switch at the end.
    These screens are plain Blade forms posted to controllers; nothing
    stateful runs before sign-in.

    step: the step label, e.g. "Step 1 of 2 · Password"
    progress: segments filled of `of` (0 hides the indicator)
--}}
@props(['title', 'step' => null, 'progress' => 0, 'of' => 2])

@php
    $reason = session('admin.reason');
    $notice = session('admin.notice');
@endphp

<x-admin::layouts.document :title="$title">
    <div class="flex min-h-dvh bg-canvas">
        <aside class="hidden w-150 shrink-0 flex-col border-e border-line bg-surface px-16 py-14 lg:flex">
            <div class="flex items-center gap-2.5">
                <svg viewBox="0 0 32 32" class="size-8.5 shrink-0 rtl:-scale-x-100" aria-hidden="true">
                    <rect width="32" height="32" rx="10" class="fill-accent" />
                    <circle cx="16" cy="16" r="8" fill="none" stroke-width="3.2" class="stroke-on-accent opacity-35" />
                    <path d="M16 8a8 8 0 0 1 7.6 10.5" fill="none" stroke-width="3.2" stroke-linecap="round" class="stroke-on-accent" />
                </svg>
                <div class="text-lead font-extrabold tracking-tight text-ink">{{ config('app.name') }} <span class="font-semibold text-ink-3">· {{ __('admin::shell.back_office') }}</span></div>
            </div>
            <div class="my-auto">
                <svg viewBox="0 0 200 200" class="size-75 rtl:-scale-x-100" aria-hidden="true">
                    <circle cx="100" cy="100" r="82" fill="none" stroke-width="15" class="stroke-sunken" />
                    <circle cx="100" cy="100" r="82" fill="none" stroke-width="15" stroke-linecap="round" stroke-dasharray="288 515" transform="rotate(-90 100 100)" class="stroke-accent" />
                    <circle cx="100" cy="100" r="56" fill="none" stroke-width="10" class="stroke-sunken" />
                    <circle cx="100" cy="100" r="56" fill="none" stroke-width="10" stroke-linecap="round" stroke-dasharray="250 352" transform="rotate(-90 100 100)" class="stroke-positive" />
                </svg>
                <p class="mt-7 text-numeral-xl font-extrabold tracking-tight text-ink leading-tight">{{ __('admin::auth.brand.headline') }}</p>
                <p class="mt-3.5 max-w-105 text-lead leading-relaxed text-ink-2">{{ __('admin::auth.brand.about') }}</p>
            </div>
            <p class="flex items-center gap-2 text-label font-semibold text-ink-3"><x-admin::icon name="audit" :size="15" />{{ __('admin::auth.brand.audit_note') }}</p>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <div class="flex justify-end px-6 pt-5">
                <div class="w-44"><livewire:admin::locale-switcher /></div>
            </div>
            <main class="mx-auto flex w-full max-w-105 flex-1 flex-col justify-center gap-4 px-4 pb-12">
                @if ($progress > 0)
                    <div class="flex gap-1.5" aria-hidden="true">
                        @for ($segment = 1; $segment <= $of; $segment++)
                            <span @class(['h-1 flex-1 rounded-full', 'bg-accent' => $segment <= $progress, 'bg-sunken-2' => $segment > $progress])></span>
                        @endfor
                    </div>
                @endif
                @if ($step)
                    <p class="text-overline font-bold tracking-widest text-ink-3 uppercase">{{ $step }}</p>
                @endif

                @if ($reason)
                    <x-admin::alert tone="info" data-test="auth-reason">{{ __('admin::auth.reasons.'.$reason) }}</x-admin::alert>
                @endif
                @if ($notice)
                    <x-admin::alert tone="success" data-test="auth-notice">{{ $notice }}</x-admin::alert>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</x-admin::layouts.document>
