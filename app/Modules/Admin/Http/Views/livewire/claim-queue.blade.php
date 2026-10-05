<div class="flex flex-col gap-5">
    <x-admin::tabs
        :label="__('admin::claims.tabs_label')"
        :current="$status"
        :items="collect(['submitted', 'approved', 'rejected', 'expired'])->map(fn (string $key): array => array_filter([
            'key' => $key,
            'label' => __('admin::claims.tabs.'.$key),
            'count' => $key === 'submitted' && $awaiting > 0 ? $awaiting : null,
            'wire' => 'setStatus',
            'test' => 'tab-'.$key,
        ], fn ($value) => $value !== null))->all()"
    />

    <form class="flex items-center gap-2.5" wire:submit="search" role="search">
        <x-admin::search class="max-w-md flex-1" name="draft" :label="__('admin::claims.search.label')" :shortcut="null" wire:model="draft" data-test="claim-search" />
        <x-admin::button type="submit" variant="secondary" data-test="claim-search-submit">{{ __('admin::claims.search.submit') }}</x-admin::button>
        @if ($q !== '')
            <x-admin::button variant="ghost" wire:click="clearSearch" data-test="claim-search-clear">{{ __('admin::claims.search.clear') }}</x-admin::button>
        @endif
    </form>

    @if ($budgetMinutes !== null)
        <x-admin::alert tone="warning" data-test="lookup-budget">{{ __('admin::claims.errors.budget_spent', ['minutes' => $budgetMinutes]) }}</x-admin::alert>
    @endif

    <x-admin::table
        :caption="__('admin::claims.title')"
        :columns="[
            ['key' => 'reference', 'label' => __('admin::claims.columns.reference')],
            ['key' => 'plan', 'label' => __('admin::claims.columns.plan')],
            ['key' => 'amount', 'label' => __('admin::claims.columns.amount')],
            ['key' => 'submitted', 'label' => __('admin::claims.columns.submitted')],
            ...($status === 'submitted' ? [['key' => 'expires', 'label' => __('admin::claims.columns.expires')]] : []),
            ['key' => 'flags', 'label' => __('admin::claims.columns.flags')],
        ]"
        :empty="$rows === []"
        :empty-title="__('admin::claims.empty')"
        :empty-tone="$q !== '' ? 'search' : 'neutral'"
    >
        @foreach ($rows as $row)
            <x-admin::table.row wire:key="claim-{{ $row['id'] }}" data-test="claim-row">
                <x-admin::table.cell :strong="true">
                    <a href="{{ route('admin.claims.show', ['claim' => $row['id']]) }}" class="rounded-badge text-accent hover:underline focus-ring-filled"><x-admin::ltr class="font-mono">{{ $row['reference'] }}</x-admin::ltr></a>
                </x-admin::table.cell>
                <x-admin::table.cell>{{ $row['plan'] }}</x-admin::table.cell>
                <x-admin::table.cell><x-admin::money :minor="$row['amount_minor']" :currency="$row['currency']" /></x-admin::table.cell>
                <x-admin::table.cell :muted="true"><x-admin::relative-time :value="$row['created_at']" /></x-admin::table.cell>
                @if ($status === 'submitted')
                    <x-admin::table.cell :muted="true"><x-admin::relative-time :value="$row['expires_at']" /></x-admin::table.cell>
                @endif
                <x-admin::table.cell>
                    @if ($row['duplicates'] > 0)
                        <x-admin::pill tone="pending" size="sm" data-test="shared-reference">{{ __('admin::claims.shared_reference') }}</x-admin::pill>
                    @endif
                </x-admin::table.cell>
            </x-admin::table.row>
        @endforeach
        @if ($rows !== [] && ($next !== '' || $after !== ''))
            <x-slot:footer>
                <x-admin::table.pagination :previous-action="$after !== '' ? 'newer' : null" :next-action="$next !== '' ? 'older' : null" data-test="claim-pagination" />
            </x-slot:footer>
        @endif
    </x-admin::table>
</div>
