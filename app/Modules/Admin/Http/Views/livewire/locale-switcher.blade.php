{{-- Language switch (spec 006, FR-037): each option is named in its own language. --}}
<div role="group" aria-label="{{ __('admin::shell.language') }}" class="flex w-full overflow-hidden rounded-control border border-field">
    @foreach (['en' => 'English', 'ar' => 'العربية'] as $option => $name)
        <button
            type="button"
            lang="{{ $option }}"
            aria-pressed="{{ $locale === $option ? 'true' : 'false' }}"
            wire:click="switchTo('{{ $option }}')"
            @class([
                'h-8 flex-1 cursor-pointer px-3 text-label focus-ring-filled',
                'border-s border-line' => ! $loop->first,
                'bg-accent-soft font-bold text-accent' => $locale === $option,
                'bg-surface font-semibold text-ink-2 hover:bg-sunken' => $locale !== $option,
            ])
        >{{ $name }}</button>
    @endforeach
</div>
