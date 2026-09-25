{{--
    Numbered pagination (spec 006, FR-015), for small fixed lists only; tables
    use cursor pagination (<x-admin::table.pagination>).
    url: a pattern with ":page", e.g. "/admin/plans?page=:page".
--}}
@props(['current', 'last', 'url'])

@php
    $pages = collect([1, $current - 1, $current, $current + 1, $last])
        ->filter(fn (int $page): bool => $page >= 1 && $page <= $last)
        ->unique()
        ->sort()
        ->values();
    $href = fn (int $page): string => str_replace(':page', (string) $page, $url);
    $square = 'inline-flex size-8.5 items-center justify-center rounded-lg text-body focus-ring-filled';
@endphp

<nav aria-label="{{ __('admin::data.pagination') }}" {{ $attributes->class(['flex items-center gap-1.5']) }}>
    @if ($current > 1)
        <a href="{{ $href($current - 1) }}" rel="prev" aria-label="{{ __('admin::data.previous') }}" class="{{ $square }} border border-line bg-surface text-ink-2"><x-admin::icon name="chevron-start" :size="16" /></a>
    @endif
    @foreach ($pages as $page)
        @if (! $loop->first && $page - $pages[$loop->index - 1] > 1)
            <span class="px-1 text-ink-3" aria-hidden="true">…</span>
        @endif
        @if ($page === $current)
            <a href="{{ $href($page) }}" aria-current="page" class="{{ $square }} bg-accent font-extrabold text-on-accent">{{ $page }}</a>
        @else
            <a href="{{ $href($page) }}" class="{{ $square }} font-semibold text-ink-2 hover:bg-sunken">{{ $page }}</a>
        @endif
    @endforeach
    @if ($current < $last)
        <a href="{{ $href($current + 1) }}" rel="next" aria-label="{{ __('admin::data.next') }}" class="{{ $square }} border border-line bg-surface text-ink-2"><x-admin::icon name="chevron-end" :size="16" /></a>
    @endif
</nav>
