{{--
    The setup key and setup link for an authenticator app (spec 018, R-4):
    no picture, so no image library. The key reads in groups of four; both
    can be copied; the link opens a mobile authenticator directly.
--}}
@props(['key', 'link', 'label'])

<div class="flex flex-col gap-4">
    <div class="flex flex-col gap-1.5" x-data="adminCopy(@js(str_replace(' ', '', $key)))">
        <span class="text-label font-semibold text-ink">{{ __('admin::auth.enrol.key') }}</span>
        <div class="flex items-center gap-2 rounded-control border border-line bg-sunken px-3.5 py-2.5">
            <x-admin::ltr class="flex-1 font-mono text-lead font-bold tracking-wider text-ink" data-test="enrol-key">{{ $key }}</x-admin::ltr>
            <x-admin::button variant="secondary" size="sm" icon="copy" x-on:click="copy()"><span x-text="copied ? @js(__('admin::auth.copied')) : @js(__('admin::auth.copy'))">{{ __('admin::auth.copy') }}</span></x-admin::button>
        </div>
    </div>
    <div class="flex flex-col gap-1.5" x-data="adminCopy(@js($link))">
        <span class="text-label font-semibold text-ink">{{ __('admin::auth.enrol.link') }}</span>
        <div class="flex items-center gap-2 rounded-control border border-line bg-sunken px-3.5 py-2.5">
            <a href="{{ $link }}" dir="ltr" data-test="enrol-link" class="min-w-0 flex-1 break-all text-caption text-accent underline underline-offset-4 focus-ring-filled">{{ $link }}</a>
            <x-admin::button variant="secondary" size="sm" icon="copy" x-on:click="copy()"><span x-text="copied ? @js(__('admin::auth.copied')) : @js(__('admin::auth.copy'))">{{ __('admin::auth.copy') }}</span></x-admin::button>
        </div>
    </div>
    <p class="text-caption text-ink-3">{{ __('admin::auth.enrol.account') }} <x-admin::ltr class="font-semibold text-ink-2">{{ $label }}</x-admin::ltr> · {{ __('admin::auth.enrol.settings') }}</p>
</div>
