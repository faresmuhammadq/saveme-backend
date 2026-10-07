<div class="flex flex-col gap-5">
    @if ($recoveryCodesLeft <= \App\Modules\Admin\Http\Livewire\Overview::LOW_CODES)
        <x-admin::alert tone="warning" :dismissible="true" data-test="low-codes">
            {{ trans_choice('admin::auth.overview.low_codes', $recoveryCodesLeft, ['count' => $recoveryCodesLeft]) }}
            <x-slot:action>
                <x-admin::button variant="secondary" size="sm" :href="route('admin.security')">{{ __('admin::shell.nav.security') }}</x-admin::button>
            </x-slot:action>
        </x-admin::alert>
    @endif

    @if (session('admin.notice'))
        <x-admin::alert tone="success" data-test="auth-notice">{{ session('admin.notice') }}</x-admin::alert>
    @endif

    <x-admin::card :title="__('admin::overview.attention.title')" data-test="needs-attention">
        @if ($claimsAwaiting === 0 && $reenrolmentsOpen === 0)
            <p class="text-body text-ink-2" data-test="nothing-needs-attention">{{ __('admin::overview.attention.nothing') }}</p>
        @else
            <ul class="flex flex-col gap-2">
                @if ($claimsAwaiting > 0)
                    <li data-test="attention-claims">
                        <x-admin::link :href="route('admin.claims')">{{ trans_choice('admin::overview.attention.claims', $claimsAwaiting, ['count' => $claimsAwaiting]) }}</x-admin::link>
                        @if ($oldestClaimAt !== null)
                            <span class="text-label text-ink-3">· {{ __('admin::overview.attention.oldest') }} <x-admin::relative-time :value="$oldestClaimAt" :threshold-days="3650" /></span>
                        @endif
                    </li>
                @endif
                @if ($reenrolmentsOpen > 0)
                    <li data-test="attention-reenrolments">
                        <x-admin::link :href="route('admin.reenrolment-requests')">{{ trans_choice('admin::overview.attention.reenrolments', $reenrolmentsOpen, ['count' => $reenrolmentsOpen]) }}</x-admin::link>
                    </li>
                @endif
            </ul>
        @endif
    </x-admin::card>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        @foreach ($figures as $key => $value)
            <x-admin::stat-tile
                :label="__('admin::overview.figures.'.$key)"
                :value="is_int($value) ? number_format($value, 0, '.', ',') : __('admin::overview.fewer_than_10')"
                data-test="figure-{{ $key }}"
            />
        @endforeach
    </div>

    <x-admin::card :title="__('admin::overview.push.title')" data-test="push-figures">
        <p class="mb-3 text-label text-ink-3">{{ __('admin::overview.push.hint') }}</p>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($push as $key => $value)
                <x-admin::stat-tile
                    :label="__('admin::overview.push.'.$key)"
                    :value="number_format($value, 0, '.', ',')"
                    data-test="push-{{ $key }}"
                />
            @endforeach
        </div>
    </x-admin::card>

    <x-admin::card :title="__('admin::overview.play.title')" data-test="play-figures">
        <p class="mb-3 text-label text-ink-3">{{ __('admin::overview.play.hint') }}</p>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($play as $key => $value)
                <x-admin::stat-tile
                    :label="__('admin::overview.play.'.$key)"
                    :value="number_format($value, 0, '.', ',')"
                    data-test="play-{{ $key }}"
                />
            @endforeach
        </div>
        @if ($playRejections !== [])
            <ul class="mt-3 flex flex-wrap gap-2" data-test="play-rejections">
                @foreach ($playRejections as $reason => $count)
                    <li><x-admin::pill tone="pending" size="sm" data-test="play-rejection-{{ $reason }}">{{ __('admin::overview.play.reasons.'.$reason) }} · {{ $count }}</x-admin::pill></li>
                @endforeach
            </ul>
        @endif
    </x-admin::card>
</div>
