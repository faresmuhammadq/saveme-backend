{{--
    Data table (spec 006, FR-013; contracts/components.md → Data).
    The table renders what it is given; sorting, selection and paging state
    belong to the owning Livewire screen, which passes action names.

    columns: list of ['key', 'label', 'align' => start|end, 'sortable' => bool]
    sort: ['key' => …, 'direction' => asc|desc] · sort-action: wire method taking the key
    selection: none | some | all · select-all-action: wire method
    loading: skeleton rows, never the empty state first · empty: no rows
    Slots: toolbar, default (<x-admin::table.row>s), footer
--}}
@props([
    'columns',
    'caption' => null,
    'sort' => null,
    'sortAction' => null,
    'selectable' => false,
    'selection' => 'none',
    'selectAllAction' => null,
    'rowActions' => false,
    'loading' => false,
    'empty' => false,
    'emptyTitle' => null,
    'emptyTone' => 'neutral',
    'skeletonRows' => 5,
])

@php
    $columnCount = count($columns) + ($selectable ? 1 : 0) + ($rowActions ? 1 : 0);
@endphp

<div {{ $attributes->class(['overflow-hidden rounded-card border border-line bg-surface']) }} @if ($loading) aria-busy="true" @endif>
    @isset($toolbar)
        <div class="flex items-center gap-2.5 border-b border-line px-4.5 py-3.5">{{ $toolbar }}</div>
    @endisset
    <table class="w-full border-collapse text-start">
        @if ($caption)<caption class="sr-only">{{ $caption }}</caption>@endif
        <thead class="bg-sunken">
            <tr class="h-11 border-b border-line">
                @if ($selectable)
                    <th scope="col" class="w-13 px-2">
                        <span class="flex justify-center">
                            <x-admin::checkbox
                                :checked="$selection === 'all'"
                                :indeterminate="$selection === 'some'"
                                :aria-label="__('admin::data.select_all')"
                                :wire:click="$selectAllAction"
                            />
                        </span>
                    </th>
                @endif
                @foreach ($columns as $column)
                    @php
                        $align = $column['align'] ?? 'start';
                        $sorted = ($sort['key'] ?? null) === $column['key'];
                        $direction = $sorted ? ($sort['direction'] ?? 'asc') : null;
                    @endphp
                    <th
                        scope="col"
                        @if (! empty($column['sortable'])) aria-sort="{{ $direction === 'asc' ? 'ascending' : ($direction === 'desc' ? 'descending' : 'none') }}" @endif
                        @class([
                            'px-3 text-overline font-bold tracking-wider text-ink-3 uppercase',
                            'text-start' => $align === 'start',
                            'text-end' => $align === 'end',
                        ])
                    >
                        @if (! empty($column['sortable']))
                            <button
                                type="button"
                                @if ($sortAction) wire:click="{{ $sortAction }}('{{ $column['key'] }}')" @endif
                                @class(['inline-flex items-center gap-1 rounded-badge uppercase focus-ring-filled', 'text-accent' => $sorted])
                            >
                                {{ $column['label'] }}
                                <span class="sr-only">{{ __('admin::data.sort_by', ['column' => $column['label']]) }}</span>
                                @if ($direction)<x-admin::icon :name="$direction === 'asc' ? 'sort-asc' : 'sort-desc'" :size="14" />@endif
                            </button>
                        @else
                            {{ $column['label'] }}
                        @endif
                    </th>
                @endforeach
                @if ($rowActions)<th scope="col" class="w-15"><span class="sr-only">{{ __('admin::data.row_actions') }}</span></th>@endif
            </tr>
        </thead>
        <tbody>
            @if ($loading)
                @for ($i = 0; $i < $skeletonRows; $i++)
                    <tr class="h-13 border-b border-line" data-skeleton-row>
                        <td colspan="{{ $columnCount }}" class="px-4.5"><x-admin::skeleton /></td>
                    </tr>
                @endfor
            @elseif ($empty)
                <tr>
                    <td colspan="{{ $columnCount }}">
                        <x-admin::empty-state :title="$emptyTitle ?? ''" :tone="$emptyTone" :level="3">{{ $emptyBody ?? '' }}</x-admin::empty-state>
                    </td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
    @isset($footer)
        <div class="flex items-center justify-between border-t border-line px-4.5 py-3 text-label text-ink-2">{{ $footer }}</div>
    @endisset
</div>
