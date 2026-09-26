{{--
    Operator notifications (spec 007, R-14): a bell with the unread count that
    opens a slide-over listing the latest notifications. Identifiers and times
    only (P-18 rule 8). `items` are arrays of scalars (OperatorNotificationView::toArray).
--}}
@props(['items' => [], 'unread' => 0, 'name' => 'operator-notifications'])

<div {{ $attributes->class(['relative inline-flex']) }}>
    <x-admin::icon-button icon="bell" :label="__('admin::notifications.label')" x-on:click="$dispatch('admin-dialog-open', { name: '{{ $name }}' })" />
    @if ($unread > 0)
        <x-admin::badge tone="danger-count" class="absolute -top-1 -end-1" aria-label="{{ __('admin::notifications.unread', ['count' => $unread]) }}">{{ $unread }}</x-admin::badge>
    @endif

    <x-admin::slide-over :name="$name" :title="__('admin::notifications.label')">
        @if ($items === [])
            <x-admin::empty-state :title="__('admin::notifications.empty')" tone="positive" />
        @else
            <ul class="flex flex-col divide-y divide-line">
                @foreach ($items as $item)
                    <li class="flex flex-col gap-1.5 py-3" data-notification="{{ $item['kind'] }}">
                        <p class="text-body font-semibold text-ink">{{ __($item['title_key']) }}</p>
                        <p class="text-label text-ink-2">{{ __($item['body_key']) }}</p>
                        <div class="flex flex-wrap items-center gap-2 text-label">
                            <span class="text-ink-2">{{ __('admin::notifications.user') }}</span>
                            <x-admin::identifier :value="$item['subject_user_id']" />
                            <x-admin::instant :value="$item['occurred_at']" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin::slide-over>
</div>
