<div class="flex flex-col gap-5">
    <x-admin::table
        :columns="[
            ['key' => 'operator', 'label' => __('admin::security.requests.operator')],
            ['key' => 'opened', 'label' => __('admin::security.requests.opened')],
            ['key' => 'expires', 'label' => __('admin::security.requests.expires')],
            ['key' => 'approvals', 'label' => __('admin::security.requests.approvals')],
        ]"
        :caption="__('admin::security.requests.title')"
        :row-actions="true"
        :empty="$requests === []"
        :empty-title="__('admin::security.requests.empty')"
        empty-tone="positive"
    >
        @foreach ($requests as $request)
            <x-admin::table.row wire:key="request-{{ $request['id'] }}" data-test="request-row">
                <x-admin::table.cell :strong="true">{{ $request['requester'] }}</x-admin::table.cell>
                <x-admin::table.cell :muted="true"><x-admin::instant :value="$request['opened_at']" /></x-admin::table.cell>
                <x-admin::table.cell :muted="true">
                    @if ($request['expired'])
                        <x-admin::pill tone="over" size="sm">{{ __('admin::security.requests.expired') }}</x-admin::pill>
                    @else
                        <x-admin::instant :value="$request['expires_at']" />
                    @endif
                </x-admin::table.cell>
                <x-admin::table.cell><x-admin::ltr>{{ $request['approvals'] }}/2</x-admin::ltr></x-admin::table.cell>
                <x-admin::table.cell>
                    @if ($request['may_act'])
                        <div class="flex justify-end gap-2">
                            <x-admin::button size="sm" icon="check" wire:click="approve('{{ $request['id'] }}')" data-test="request-approve">{{ __('admin::security.requests.approve') }}</x-admin::button>
                            <x-admin::button size="sm" variant="secondary" wire:click="decline('{{ $request['id'] }}')" data-test="request-decline">{{ __('admin::security.requests.decline') }}</x-admin::button>
                        </div>
                    @endif
                </x-admin::table.cell>
            </x-admin::table.row>
        @endforeach
    </x-admin::table>

    <x-admin::factor-prompt />
    <x-admin::reason-prompt :action-key="$reasonAction ?? 'reenrolment.approve'" :error="$errors->first('reason')" />
</div>
