{{--
    Operator notifications (spec 007, R-14): a bell with the unread count that
    opens a slide-over listing the latest notifications. Identifiers and times
    only (P-18 rule 8). `items` are arrays of scalars (OperatorNotificationView::toArray).
    `live` (the Livewire bell) makes each item open its person's account and
    adds "Mark all read", acting on this operator's own read marks (spec 021, US6).
--}}
@props(['items' => [], 'unread' => 0, 'name' => 'operator-notifications', 'live' => false])

<div {{ $attributes->class(['relative inline-flex']) }}>
    <x-admin::icon-button icon="bell" :label="__('admin::notifications.label')" x-on:click="$dispatch('admin-dialog-open', { name: '{{ $name }}' })" />
    @if ($unread > 0)
        <x-admin::badge tone="danger-count" class="absolute -top-1 -end-1" aria-label="{{ __('admin::notifications.unread', ['count' => $unread]) }}">{{ $unread }}</x-admin::badge>
    @endif

    <x-admin::slide-over :name="$name" :title="__('admin::notifications.label')">
        @if ($live && $unread > 0)
            <div class="mb-2 flex justify-end">
                <x-admin::button variant="ghost" size="sm" wire:click="markAllRead" data-test="notifications-mark-all">{{ __('admin::notifications.mark_all_read') }}</x-admin::button>
            </div>
        @endif
        @if ($items === [])
            <x-admin::empty-state :title="__('admin::notifications.empty')" tone="positive" />
        @else
            <ul class="flex flex-col divide-y divide-line">
                @foreach ($items as $item)
                    <li class="flex flex-col gap-1.5 py-3" data-notification="{{ $item['kind'] }}" @if ($live) data-test="notification-{{ $item['id'] }}" data-read="{{ ($item['read'] ?? false) ? 'true' : 'false' }}" @endif>
                        <p @class(['text-body text-ink', 'font-semibold' => ! ($item['read'] ?? false), 'font-medium text-ink-2' => ($item['read'] ?? false)])>{{ __($item['title_key']) }}@if ($live && ! ($item['read'] ?? false))<span class="sr-only"> ({{ __('admin::notifications.unread_item') }})</span>@endif</p>
                        <p class="text-label text-ink-2">{{ __($item['body_key']) }}</p>
                        <div class="flex flex-wrap items-center gap-2 text-label">
                            <span class="text-ink-2">{{ __('admin::notifications.user') }}</span>
                            <x-admin::identifier :value="$item['subject_user_id']" />
                            <x-admin::instant :value="$item['occurred_at']" />
                        </div>
                        @if ($live)
                            <div>
                                <x-admin::button variant="secondary" size="sm" wire:click="open('{{ $item['id'] }}')" data-test="notification-open-{{ $item['id'] }}">{{ __('admin::notifications.open_account') }}</x-admin::button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin::slide-over>
</div>
