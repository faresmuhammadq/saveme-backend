{{-- Page header (spec 006, FR-006): title, optional subtitle and status, action area. --}}
@props(['title', 'subtitle' => null])

<div class="flex items-end gap-4">
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2.5">
            <h1 class="text-h1 font-extrabold tracking-tight text-ink">{{ $title }}</h1>
            @if (isset($status) && $status->isNotEmpty()){{ $status }}@endif
        </div>
        @if ($subtitle)<p class="mt-1 text-body text-ink-2">{{ $subtitle }}</p>@endif
    </div>
    @if (isset($actions) && $actions->isNotEmpty())<div class="flex shrink-0 items-center gap-2.5">{{ $actions }}</div>@endif
</div>
