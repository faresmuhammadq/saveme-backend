{{-- Breadcrumbs (spec 006, FR-015). items: list of ['label', 'href' => ?string]; the last is the current page. --}}
@props(['items'])

<nav aria-label="{{ __('admin::shell.breadcrumb') }}" {{ $attributes }}>
    <ol class="flex items-center gap-1.5 text-body text-ink-2">
        @foreach ($items as $item)
            <li class="flex items-center gap-1.5">
                @if ($loop->last)
                    <span aria-current="page" class="font-bold text-ink">{{ $item['label'] }}</span>
                @else
                    @if (! empty($item['href']))
                        <a href="{{ $item['href'] }}" class="rounded-badge text-ink-2 no-underline hover:text-ink focus-ring-filled">{{ $item['label'] }}</a>
                    @else
                        <span>{{ $item['label'] }}</span>
                    @endif
                    <x-admin::icon name="chevron-end" :size="14" class="text-ink-3" />
                @endif
            </li>
        @endforeach
    </ol>
</nav>
