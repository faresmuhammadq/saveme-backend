{{--
    Menu item (spec 006, FR-011). tone: default | danger.
    `opens-prompt` adds the trailing "…" for items that open the reason prompt.
    A disabled item stays visible and says why.
--}}
@props(['icon' => null, 'shortcut' => null, 'tone' => 'default', 'disabled' => false, 'disabledReason' => null, 'opensPrompt' => false, 'highlighted' => false, 'href' => null])

@php $reasonId = $disabledReason ? 'menu-reason-'.\Illuminate\Support\Str::random(8) : null; @endphp

<{{ $href ? 'a' : 'button' }}
    @if ($href) href="{{ $href }}" @else type="button" @endif
    role="menuitem"
    tabindex="-1"
    @if ($disabled) aria-disabled="true" @endif
    @if ($reasonId) aria-describedby="{{ $reasonId }}" @endif
    @if ($shortcut) data-shortcut="{{ $shortcut }}" aria-keyshortcuts="{{ $shortcut }}" @endif
    {{ $attributes->class([
        'flex h-9.5 w-full items-center gap-2.5 rounded-lg px-2.5 text-start text-body outline-none',
        'focus:bg-accent-soft hover:bg-sunken' => ! $disabled,
        'bg-accent-soft font-semibold' => $highlighted,
        'text-ink' => $tone === 'default' && ! $disabled,
        'font-semibold text-over-text' => $tone === 'danger' && ! $disabled,
        'cursor-not-allowed text-ink-3' => $disabled,
    ]) }}
>
    @if ($icon)<x-admin::icon :name="$icon" :class="$tone === 'default' && ! $disabled ? 'text-ink-2' : ''" />@endif
    <span class="flex-1 whitespace-nowrap">{{ $slot }}@if ($opensPrompt)…@endif</span>
    @if ($disabledReason)<span id="{{ $reasonId }}" class="text-caption whitespace-nowrap">{{ $disabledReason }}</span>@endif
    @if ($shortcut)<kbd class="font-mono text-caption text-ink-3">{{ $shortcut }}</kbd>@endif
</{{ $href ? 'a' : 'button' }}>
