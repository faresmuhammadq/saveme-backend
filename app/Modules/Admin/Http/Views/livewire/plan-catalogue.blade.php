@php
    $dt = 'col-span-1 border-t border-line py-2.5 text-ink-2';
    $dd = 'col-span-2 border-t border-line py-2.5 font-semibold text-ink';
    $capability = function (string $key, mixed $value): string {
        $quota = in_array($key, ['sharing.max_members_per_wallet', 'sharing.max_members_per_budget', 'wallets.max'], true);

        if ($quota) {
            return $value === null ? __('admin::plans.values.unlimited') : (string) $value;
        }

        return $value === true ? __('admin::plans.values.on') : __('admin::plans.values.off');
    };
@endphp

<div class="flex flex-col gap-5">
    @forelse ($plans as $plan)
        <x-admin::card wire:key="plan-{{ $plan['id'] }}" :title="$plan['name'][$locale]" data-test="plan-{{ $plan['id'] }}">
            <div class="flex flex-wrap items-center gap-2 pb-3">
                <x-admin::pill :tone="$plan['status'] === 'active' ? 'positive' : 'neutral'" size="sm" data-test="status-{{ $plan['status'] }}">{{ __('admin::plans.status.'.$plan['status']) }}</x-admin::pill>
                @if ($plan['is_default'])
                    <x-admin::pill tone="info" size="sm" data-test="default-pill">{{ __('admin::plans.default') }}</x-admin::pill>
                @endif
                <span class="flex-1"></span>
                <x-admin::button
                    variant="secondary" size="sm" icon="edit" :href="route('admin.plans.edit', ['plan' => $plan['id']])"
                    :aria-label="__('admin::plans.card.edit_label', ['plan' => $plan['name'][$locale]])" data-test="edit-{{ $plan['id'] }}"
                >{{ __('admin::plans.card.edit') }}</x-admin::button>
            </div>

            <x-admin::description-list>
                <dt class="{{ $dt }}">{{ __('admin::plans.card.names') }}</dt>
                <dd class="{{ $dd }}"><bdi dir="ltr">{{ $plan['name']['en'] }}</bdi> · <bdi dir="rtl">{{ $plan['name']['ar'] }}</bdi></dd>
                <dt class="{{ $dt }}">{{ __('admin::plans.card.period') }}</dt>
                <dd class="{{ $dd }}">{{ __('admin::plans.period.'.$plan['period_type'], ['days' => $plan['period_days']]) }}</dd>
                <dt class="{{ $dt }}">{{ __('admin::plans.card.order') }}</dt>
                <dd class="{{ $dd }}"><bdi dir="ltr">{{ $plan['sort_order'] }}</bdi></dd>
                <dt class="{{ $dt }}">{{ __('admin::plans.card.instapay') }}</dt>
                <dd class="{{ $dd }}" data-test="instapay-{{ $plan['id'] }}">
                    @if ($plan['instapay_price'] === null)
                        {{ __('admin::plans.card.instapay_none') }}
                    @else
                        <x-admin::money :minor="$plan['instapay_price']['amount_minor']" :currency="$plan['instapay_price']['currency']" />
                    @endif
                </dd>
                <dt class="{{ $dt }}">{{ __('admin::plans.card.google_play') }}</dt>
                <dd class="{{ $dd }}">
                    @forelse ($plan['google_play_prices'] as $price)
                        <span class="flex flex-wrap items-center gap-2">
                            <x-admin::money :minor="$price['amount_minor']" :currency="$price['currency']" />
                            @if ($price['product_id'] !== null)<x-admin::ltr class="font-mono text-mono text-ink-2">{{ $price['product_id'] }}</x-admin::ltr>@endif
                        </span>
                    @empty
                        {{ __('admin::plans.card.google_play_none') }}
                    @endforelse
                </dd>
                <dt class="{{ $dt }}">{{ __('admin::plans.card.governed') }}</dt>
                <dd class="{{ $dd }}" data-test="governed-{{ $plan['id'] }}">
                    @if ($plan['governed_people'] === null)
                        {{ __('admin::plans.card.governed_default') }}
                    @elseif ($plan['governed_people'] === \App\Modules\Admin\Domain\AnonymityFloor::MARKER)
                        {{ __('admin::plans.card.fewer_than_10') }}
                    @else
                        <bdi dir="ltr">{{ $plan['governed_people'] }}</bdi>
                    @endif
                </dd>
                @foreach ($plan['features'] as $key => $value)
                    <dt class="{{ $dt }}">{{ __('admin::plans.capabilities.'.str_replace('.', '_', $key)) }}</dt>
                    <dd class="{{ $dd }}" data-test="capability-{{ $plan['id'] }}-{{ str_replace('.', '_', $key) }}"><bdi dir="auto">{{ $capability($key, $value) }}</bdi></dd>
                @endforeach
            </x-admin::description-list>
        </x-admin::card>
    @empty
        <x-admin::alert tone="info" data-test="no-plans">{{ __('admin::plans.empty') }}</x-admin::alert>
    @endforelse
</div>
