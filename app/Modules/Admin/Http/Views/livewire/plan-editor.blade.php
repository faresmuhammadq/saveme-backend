@php
    $isDefault = $stored['is_default'];
    $isActive = $stored['status'] === 'active';
    $capabilityOrder = array_keys($stored['features']);
@endphp

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-admin::pill :tone="$isActive ? 'positive' : 'neutral'" data-test="plan-status">{{ __('admin::plans.status.'.$stored['status']) }}</x-admin::pill>
            @if ($isDefault)
                <x-admin::pill tone="info" size="sm" data-test="default-pill">{{ __('admin::plans.default') }}</x-admin::pill>
            @endif
        </div>
        <div class="flex flex-wrap gap-2.5">
            <x-admin::button variant="ghost" icon="chevron-start" :href="route('admin.plans')" data-test="back">{{ __('admin::plans.editor.back') }}</x-admin::button>
            @if ($canRetire)
                <x-admin::button variant="danger" wire:click="startRetire" data-test="retire">{{ __('admin::plans.editor.retire') }}</x-admin::button>
            @endif
        </div>
    </div>

    @if (! $isActive)
        <x-admin::alert tone="info" data-test="retired-note">{{ __('admin::plans.editor.retired_note') }}</x-admin::alert>
    @endif

    @if ($stale)
        <x-admin::alert tone="warning" data-test="stale-alert">
            <span class="flex flex-wrap items-center gap-3">
                {{ __('admin::plans.stale.message') }}
                <x-admin::button variant="secondary" size="sm" wire:click="reload" data-test="reload">{{ __('admin::plans.stale.reload') }}</x-admin::button>
            </span>
        </x-admin::alert>
    @endif

    <x-admin::card :title="__('admin::plans.editor.details')">
        <div class="grid gap-4 md:grid-cols-2">
            <x-admin::input name="draft.name_en" id="name_en" :label="__('admin::plans.editor.name_en')" dir="ltr" :error="$errors->first('draft.name_en')" wire:model.live.debounce.300ms="draft.name_en" data-test="name-en" />
            <x-admin::input name="draft.name_ar" id="name_ar" :label="__('admin::plans.editor.name_ar')" dir="rtl" :error="$errors->first('draft.name_ar')" wire:model.live.debounce.300ms="draft.name_ar" data-test="name-ar" />
            <x-admin::number name="draft.sort_order" id="sort_order" :label="__('admin::plans.editor.sort_order')" :error="$errors->first('draft.sort_order')" wire:model.live.debounce.300ms="draft.sort_order" data-test="sort-order" />
        </div>
    </x-admin::card>

    <x-admin::card :title="__('admin::plans.editor.price')">
        @if ($draft['price_on'])
            <div class="grid gap-4 md:grid-cols-2">
                <x-admin::number name="draft.price_amount" id="price_amount" :label="__('admin::plans.editor.price_amount')" :hint="__('admin::plans.editor.price_hint')" :error="$errors->first('draft.price_amount')" wire:model.live.debounce.300ms="draft.price_amount" data-test="price-amount" />
                <x-admin::select name="draft.price_currency" id="price_currency" :label="__('admin::plans.editor.price_currency')" :options="$currencies" :value="$draft['price_currency']" wire:model.live="draft.price_currency" data-test="price-currency" />
            </div>
            <div class="pt-3">
                <x-admin::button variant="ghost" size="sm" wire:click="$set('draft.price_on', false)" data-test="remove-price">{{ __('admin::plans.editor.remove_price') }}</x-admin::button>
            </div>
        @else
            <p class="text-body text-ink-2" data-test="no-price">{{ __('admin::plans.editor.no_price') }}</p>
            <div class="pt-3">
                <x-admin::button variant="secondary" size="sm" wire:click="$set('draft.price_on', true)" data-test="add-price">{{ __('admin::plans.editor.add_price') }}</x-admin::button>
            </div>
        @endif
        <p class="pt-3 text-label text-ink-2">{{ __('admin::plans.editor.google_play_note') }}</p>
    </x-admin::card>

    <x-admin::card :title="__('admin::plans.editor.capabilities')">
        <div class="flex flex-col gap-4">
            @foreach ($capabilityOrder as $key)
                @php
                    $safe = str_replace('.', '_', $key);
                    $isQuota = in_array($safe, $quotaKeys, true);
                    $locked = $isDefault && in_array($safe, $lockedOnDefault, true);
                    $label = __('admin::plans.capabilities.'.$safe);
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4 first:border-t-0 first:pt-0" wire:key="capability-{{ $safe }}" data-test="capability-{{ $safe }}">
                    <div class="flex flex-col">
                        <span class="text-body font-semibold">{{ $label }}</span>
                        @if ($locked)<span class="text-caption text-ink-3" data-test="locked-{{ $safe }}">{{ __('admin::plans.editor.locked') }}</span>@endif
                    </div>
                    @if ($isQuota)
                        <div class="flex flex-wrap items-center gap-4">
                            @if (! ($draft['unlimited'][$safe] ?? false))
                                <x-admin::number :name="'draft.quota.'.$safe" :id="'quota_'.$safe" :aria-label="$label" :disabled="$locked" :error="$errors->first('draft.quota.'.$safe)" wire:model.live.debounce.300ms="draft.quota.{{ $safe }}" data-test="quota-{{ $safe }}" />
                            @endif
                            <x-admin::checkbox :name="'unlimited_'.$safe" :label="__('admin::plans.editor.unlimited')" :disabled="$locked" :checked="$draft['unlimited'][$safe] ?? false" wire:model.live="draft.unlimited.{{ $safe }}" data-test="unlimited-{{ $safe }}" />
                        </div>
                    @else
                        <x-admin::checkbox :name="'flag_'.$safe" :label="__('admin::plans.values.on')" :disabled="$locked" :checked="$draft['flag'][$safe] ?? false" wire:model.live="draft.flag.{{ $safe }}" data-test="flag-{{ $safe }}" />
                    @endif
                </div>
            @endforeach
        </div>
    </x-admin::card>

    @if ($differences !== [])
        <div class="sticky bottom-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-surface p-4 shadow-overlay" role="region" aria-label="{{ trans_choice('admin::plans.unsaved.summary', count($differences), ['count' => count($differences)]) }}" data-test="unsaved-bar">
            <p class="text-body font-semibold text-ink">
                {{ trans_choice('admin::plans.unsaved.summary', count($differences), ['count' => count($differences)]) }}
                <span class="font-normal text-ink-2" data-test="unsaved-list">· {{ collect($differences)->pluck('label')->implode(', ') }}</span>
            </p>
            <div class="flex gap-2.5">
                <x-admin::button variant="ghost" wire:click="discard" data-test="discard">{{ __('admin::plans.unsaved.discard') }}</x-admin::button>
                <x-admin::button wire:click="review" data-test="review">{{ __('admin::plans.unsaved.review') }}</x-admin::button>
            </div>
        </div>
    @endif

    <x-admin::dialog name="review-plan" :title="__('admin::plans.review.title')" :description="__('admin::plans.review.body')" icon="check" size="lg">
        <div class="flex flex-col gap-4">
            <ul class="flex flex-col gap-1.5 text-body" data-test="review-changes">
                @foreach ($differences as $change)
                    <li data-test="change-{{ $change['field'] }}">
                        <span class="font-semibold">{{ $change['label'] }}:</span>
                        <bdi dir="auto" class="text-ink-2">{{ $change['before'] }}</bdi> → <bdi dir="auto" class="font-semibold">{{ $change['after'] }}</bdi>
                    </li>
                @endforeach
            </ul>
            <p class="text-body text-ink-2" data-test="review-claims">{{ trans_choice('admin::plans.review.claims', $stored['submitted_claims'], ['count' => $stored['submitted_claims']]) }}</p>
            @if ($stored['governed_people'] !== null)
                <p class="text-body text-ink-2" data-test="review-people">
                    @if ($stored['governed_people'] > 0 && $stored['governed_people'] < \App\Modules\Admin\Domain\AnonymityFloor::FLOOR)
                        {{ __('admin::plans.review.people_fewer') }}
                    @else
                        {{ trans_choice('admin::plans.review.people', $stored['governed_people'], ['count' => $stored['governed_people']]) }}
                    @endif
                </p>
            @endif
            @if ($membersLowered)
                <x-admin::alert tone="info" data-test="members-note">{{ __('admin::plans.review.members') }}</x-admin::alert>
            @endif
            <x-admin::textarea name="reason" :label="__('admin::plans.review.reason')" :hint="__('admin::plans.reason_hint')" :max="500" :value="$reason" :error="$errors->first('reason')" wire:model="reason" data-test="save-reason" />
        </div>
        <x-slot:footer>
            <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
            <x-admin::button wire:click="confirmSave" data-test="save-confirm">{{ __('admin::plans.review.confirm') }}</x-admin::button>
        </x-slot:footer>
    </x-admin::dialog>

    @if ($canRetire)
        <x-admin::dialog name="retire-plan" :title="__('admin::plans.retire_dialog.title')" :description="__('admin::plans.retire_dialog.body')" icon="error" tone="danger" size="md">
            <div class="flex flex-col gap-4">
                <p class="text-body text-ink-2" data-test="retire-claims">{{ trans_choice('admin::plans.review.claims', $stored['submitted_claims'], ['count' => $stored['submitted_claims']]) }}</p>
                @if ($stored['governed_people'] !== null)
                    <p class="text-body text-ink-2" data-test="retire-people">
                        @if ($stored['governed_people'] > 0 && $stored['governed_people'] < \App\Modules\Admin\Domain\AnonymityFloor::FLOOR)
                            {{ __('admin::plans.review.people_fewer') }}
                        @else
                            {{ trans_choice('admin::plans.review.people', $stored['governed_people'], ['count' => $stored['governed_people']]) }}
                        @endif
                    </p>
                @endif
                <x-admin::textarea name="retireReason" :label="__('admin::plans.retire_dialog.reason')" :hint="__('admin::plans.reason_hint')" :max="500" :value="$retireReason" :error="$errors->first('retireReason')" wire:model="retireReason" data-test="retire-reason" />
            </div>
            <x-slot:footer>
                <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
                <x-admin::button variant="danger" wire:click="confirmRetire" data-test="retire-confirm">{{ __('admin::plans.retire_dialog.confirm') }}</x-admin::button>
            </x-slot:footer>
        </x-admin::dialog>
    @endif

    <x-admin::factor-prompt />
</div>
