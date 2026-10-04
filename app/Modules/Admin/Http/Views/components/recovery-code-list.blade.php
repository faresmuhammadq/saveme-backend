{{--
    The ten recovery codes, shown once (spec 018, R-11): two columns, always
    left to right, with "Copy all" and "Download as text" done in the browser.
    The text never goes back to the server.
--}}
@props(['codes'])

@php $text = implode("\n", $codes)."\n"; @endphp

<div class="flex flex-col gap-3">
    <ol data-test="recovery-code-list" dir="ltr" class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-control border border-line bg-sunken px-4 py-3.5 font-mono text-lead font-bold text-ink">
        @foreach ($codes as $code)
            <li><x-admin::ltr>{{ $code }}</x-admin::ltr></li>
        @endforeach
    </ol>
    <div class="flex flex-wrap gap-2.5">
        <div x-data="adminCopy(@js($text))">
            <x-admin::button variant="secondary" size="sm" icon="copy" x-on:click="copy()"><span x-text="copied ? @js(__('admin::auth.copied')) : @js(__('admin::auth.codes.copy_all'))">{{ __('admin::auth.codes.copy_all') }}</span></x-admin::button>
        </div>
        <div x-data="adminDownload(@js($text), 'save-me-recovery-codes.txt')">
            <x-admin::button variant="secondary" size="sm" icon="export" x-on:click="save()">{{ __('admin::auth.codes.download') }}</x-admin::button>
        </div>
    </div>
</div>
