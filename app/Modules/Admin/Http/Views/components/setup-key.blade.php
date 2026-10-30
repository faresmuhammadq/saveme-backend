{{--
    The setup key and setup link for an authenticator app (spec 018, R-4):
    The setup key, setup link and a QR code for an authenticator app (spec 018,
    R-4). The key reads in groups of four; key and link are shown as plain
    text and can be copied; the QR code is an inline SVG drawn on the server.
--}}
@props(['key', 'link', 'label'])

<div class="flex flex-col gap-4">
    <div class="flex flex-col items-center gap-1.5">
        <div class="rounded-control border border-line bg-white p-2 [&>svg]:size-48" role="img" aria-label="{{ __('admin::auth.enrol.qr') }}" data-test="enrol-qr">{!! app(\App\Modules\Admin\Http\Presenters\SetupQrCodePresenter::class)->svg($link) !!}</div>
        <span class="text-caption text-ink-3">{{ __('admin::auth.enrol.qr_hint') }}</span>
    </div>
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
            <x-admin::ltr class="min-w-0 flex-1 select-all break-all font-mono text-caption text-ink" data-test="enrol-link">{{ $link }}</x-admin::ltr>
            <x-admin::button variant="secondary" size="sm" icon="copy" x-on:click="copy()"><span x-text="copied ? @js(__('admin::auth.copied')) : @js(__('admin::auth.copy'))">{{ __('admin::auth.copy') }}</span></x-admin::button>
        </div>
    </div>
    <p class="text-caption text-ink-3">{{ __('admin::auth.enrol.account') }} <x-admin::ltr class="font-semibold text-ink-2">{{ $label }}</x-admin::ltr> · {{ __('admin::auth.enrol.settings') }}</p>
</div>
