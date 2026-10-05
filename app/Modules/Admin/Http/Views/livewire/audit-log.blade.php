@php
    $day = fn (string $date): string => '<bdi dir="ltr">'.e($date).'</bdi>';
    $range = new \Illuminate\Support\HtmlString(__('admin::audit.range', ['from' => $day($applied['from'] ?? ''), 'to' => $day($applied['to'] ?? '')]));
    $withAny = fn (array $values): array => array_merge(
        [['value' => '', 'label' => __('admin::audit.filters.any')]],
        array_map(fn (string $value): array => ['value' => $value, 'label' => $value], $values),
    );
@endphp

<div class="flex flex-col gap-5">
    <x-admin::alert tone="info" data-test="audit-read-note">{{ __('admin::audit.note') }}</x-admin::alert>

    <x-admin::card>
        <form class="flex flex-col gap-4" wire:submit="apply" role="search" aria-label="{{ __('admin::audit.filters.label') }}">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <x-admin::select name="event" :label="__('admin::audit.filters.event')" :options="$withAny($events)" :value="$event" :error="$errors->first('event')" wire:model="event" data-test="filter-event" />
                <x-admin::select name="actorType" :label="__('admin::audit.filters.actor_type')" :options="$withAny($actorTypes)" :value="$actorType" :error="$errors->first('actorType')" wire:model="actorType" data-test="filter-actor-type" />
                <x-admin::input name="actorId" :label="__('admin::audit.filters.actor_id')" :error="$errors->first('actorId')" dir="ltr" mono autocomplete="off" spellcheck="false" wire:model="actorId" data-test="filter-actor-id" />
                <x-admin::select name="subjectType" :label="__('admin::audit.filters.subject_type')" :options="$withAny($subjectTypes)" :value="$subjectType" :error="$errors->first('subjectType')" wire:model="subjectType" data-test="filter-subject-type" />
                <x-admin::input name="subjectId" :label="__('admin::audit.filters.subject_id')" :error="$errors->first('subjectId')" dir="ltr" mono autocomplete="off" spellcheck="false" wire:model="subjectId" data-test="filter-subject-id" />
                <div class="grid grid-cols-2 gap-4">
                    <x-admin::date-picker name="from" :label="__('admin::audit.filters.from')" :value="$from" :error="$errors->first('from')" wire:model="from" data-test="filter-from" />
                    <x-admin::date-picker name="to" :label="__('admin::audit.filters.to')" :value="$to" :error="$errors->first('to')" wire:model="to" data-test="filter-to" />
                </div>
            </div>
            <div class="flex items-center gap-2.5">
                <x-admin::button type="submit" icon="search" data-test="audit-apply">{{ __('admin::audit.filters.apply') }}</x-admin::button>
                <x-admin::button variant="ghost" wire:click="clearFilters" data-test="audit-reset">{{ __('admin::audit.filters.reset') }}</x-admin::button>
            </div>
        </form>
    </x-admin::card>

    <div x-data="{ open: {} }">
        <x-admin::table
            :caption="__('admin::audit.title')"
            :row-actions="true"
            :columns="[
                ['key' => 'when', 'label' => __('admin::audit.columns.when', ['zone' => config('admin.timezone')])],
                ['key' => 'event', 'label' => __('admin::audit.columns.event')],
                ['key' => 'actor', 'label' => __('admin::audit.columns.actor')],
                ['key' => 'subject', 'label' => __('admin::audit.columns.subject')],
                ['key' => 'origin', 'label' => __('admin::audit.columns.origin')],
            ]"
            :empty="$rows === []"
            :empty-title="$loaded ? __('admin::audit.empty') : __('admin::audit.fix_filters')"
        >
            @foreach ($rows as $row)
                <x-admin::table.row wire:key="audit-{{ $row['id'] }}" data-test="audit-row">
                    <x-admin::table.cell :muted="true"><x-admin::instant :value="$row['occurred_at']" /></x-admin::table.cell>
                    <x-admin::table.cell><x-admin::ltr class="font-mono text-mono">{{ $row['event'] }}</x-admin::ltr></x-admin::table.cell>
                    <x-admin::table.cell data-test="audit-actor">
                        @if ($row['actor_type'] === 'system')
                            {{ __('admin::audit.actor.system') }}
                        @elseif ($row['actor_type'] === 'operator')
                            <span class="flex flex-col">
                                <span class="font-semibold text-ink">{{ $row['actor_name'] !== '' ? $row['actor_name'] : __('admin::audit.actor.operator') }}</span>
                                <x-admin::ltr class="font-mono text-caption text-ink-3">{{ \Illuminate\Support\Str::limit($row['actor_id'], 13, '…') }}</x-admin::ltr>
                            </span>
                        @else
                            <span class="flex flex-col">
                                <span class="text-caption text-ink-3">{{ __('admin::audit.actor.user') }}</span>
                                <x-admin::ltr class="font-mono text-mono">{{ $row['actor_id'] }}</x-admin::ltr>
                            </span>
                        @endif
                    </x-admin::table.cell>
                    <x-admin::table.cell data-test="audit-subject">
                        <span class="flex flex-col">
                            <span class="text-caption text-ink-3">{{ __('admin::audit.subject.'.$row['subject_type']) }}</span>
                            @if ($row['subject_id'] !== '')
                                @if ($row['subject_url'] !== '')
                                    <a href="{{ $row['subject_url'] }}" class="rounded-badge text-accent hover:underline focus-ring-filled" data-test="subject-link"><x-admin::ltr class="font-mono text-mono">{{ $row['subject_id'] }}</x-admin::ltr></a>
                                @else
                                    <x-admin::ltr class="font-mono text-mono">{{ $row['subject_id'] }}</x-admin::ltr>
                                @endif
                            @endif
                        </span>
                    </x-admin::table.cell>
                    <x-admin::table.cell :muted="true">@if ($row['origin'] !== '')<x-admin::ltr class="font-mono text-mono">{{ $row['origin'] }}</x-admin::ltr>@endif</x-admin::table.cell>
                    <x-admin::table.cell align="end">
                        <button
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-sunken hover:text-ink focus-ring-filled"
                            aria-label="{{ __('admin::audit.expand', ['event' => $row['event']]) }}"
                            aria-controls="audit-detail-{{ $row['id'] }}"
                            aria-expanded="false"
                            x-bind:aria-expanded="(!! open['{{ $row['id'] }}']).toString()"
                            x-on:click="open['{{ $row['id'] }}'] = ! open['{{ $row['id'] }}']"
                            data-test="audit-toggle"
                        >
                            <span x-show="! open['{{ $row['id'] }}']"><x-admin::icon name="chevron-down" :size="18" /></span>
                            <span x-show="open['{{ $row['id'] }}']" x-cloak><x-admin::icon name="chevron-up" :size="18" /></span>
                        </button>
                    </x-admin::table.cell>
                </x-admin::table.row>
                <tr wire:key="audit-detail-{{ $row['id'] }}" id="audit-detail-{{ $row['id'] }}" x-show="open['{{ $row['id'] }}']" x-cloak class="border-b border-line bg-sunken" data-test="audit-detail">
                    <td colspan="6" class="px-3 py-4">
                        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 text-body">
                            @foreach ($row['detail'] as $member => $value)
                                <dt class="text-ink-2"><x-admin::ltr class="font-mono text-mono">{{ $member }}</x-admin::ltr></dt>
                                <dd class="font-semibold text-ink"><x-admin::ltr>{{ $value }}</x-admin::ltr></dd>
                            @endforeach
                            <dt class="text-ink-2">{{ __('admin::audit.detail.request_id') }}</dt>
                            <dd class="font-semibold text-ink"><x-admin::ltr class="font-mono text-mono">{{ $row['request_id'] !== '' ? $row['request_id'] : '—' }}</x-admin::ltr></dd>
                            <dt class="text-ink-2">{{ __('admin::audit.detail.entry_id') }}</dt>
                            <dd class="font-semibold text-ink"><x-admin::ltr class="font-mono text-mono">{{ $row['id'] }}</x-admin::ltr></dd>
                            <dt class="text-ink-2">{{ __('admin::audit.detail.origin') }}</dt>
                            <dd class="font-semibold text-ink"><x-admin::ltr class="font-mono text-mono">{{ $row['origin'] !== '' ? $row['origin'] : '—' }}</x-admin::ltr></dd>
                        </dl>
                    </td>
                </tr>
            @endforeach
            @if ($rows !== [])
                <x-slot:footer>
                    <x-admin::table.pagination
                        :previous-action="$newerCursor !== '' ? 'newer' : null"
                        :next-action="$olderCursor !== '' ? 'older' : null"
                        :summary="$range"
                        data-test="audit-pagination"
                    />
                </x-slot:footer>
            @endif
        </x-admin::table>
    </div>
</div>
