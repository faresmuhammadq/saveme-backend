@php
    $dt = 'col-span-1 border-t border-line py-2.5 text-ink-2';
    $dd = 'col-span-2 border-t border-line py-2.5 font-semibold text-ink';
    $statusTone = ['submitted' => 'pending', 'approved' => 'positive', 'rejected' => 'over', 'expired' => 'neutral'][$review['status']] ?? 'neutral';
@endphp

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-admin::identifier :value="$facts['reference']" :label="__('admin::claims.review.reference')" data-test="claim-reference" />
            <x-admin::pill :tone="$statusTone" data-test="claim-status">{{ __('admin::claims.status.'.$review['status']) }}</x-admin::pill>
        </div>
        <div class="flex flex-wrap gap-2.5">
            @if ($canDecide)
                <x-admin::button variant="secondary" x-on:click="$dispatch('admin-dialog-open', { name: 'reject-claim' })" data-test="reject">{{ __('admin::claims.actions.reject') }}</x-admin::button>
                <x-admin::button icon="check" x-on:click="$dispatch('admin-dialog-open', { name: 'approve-claim' })" data-test="approve">{{ __('admin::claims.actions.approve') }}</x-admin::button>
            @endif
            @if ($canRevoke)
                <x-admin::button variant="danger" wire:click="revokeCode" data-test="revoke-code">{{ __('admin::claims.actions.revoke') }}</x-admin::button>
            @endif
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-admin::card :title="__('admin::claims.review.payment')">
            <x-admin::description-list>
                <dt class="{{ $dt }}">{{ __('admin::claims.review.plan') }}</dt>
                <dd class="{{ $dd }}">{{ $facts['plan'] }}</dd>
                <dt class="{{ $dt }}">{{ __('admin::claims.review.amount') }}</dt>
                <dd class="{{ $dd }}"><x-admin::money :minor="$facts['amount_minor']" :currency="$facts['currency']" /></dd>
                <dt class="{{ $dt }}">{{ __('admin::claims.review.matches_price') }}</dt>
                <dd class="{{ $dd }}" data-test="matches-price">
                    <x-admin::pill :tone="$facts['matches_price'] ? 'positive' : 'over'" size="sm">{{ $facts['matches_price'] ? __('admin::claims.review.yes') : __('admin::claims.review.no') }}</x-admin::pill>
                </dd>
                <dt class="{{ $dt }}">{{ __('admin::claims.review.submitted') }}</dt>
                <dd class="{{ $dd }}"><x-admin::instant :value="$facts['created_at']" /></dd>
                <dt class="{{ $dt }}">{{ __('admin::claims.review.expires') }}</dt>
                <dd class="{{ $dd }}"><x-admin::instant :value="$review['expires_at']" /></dd>
                <dt class="{{ $dt }}">{{ __('admin::claims.review.language') }}</dt>
                <dd class="{{ $dd }}">{{ __('admin::claims.review.languages.'.$facts['language']) }}</dd>
            </x-admin::description-list>
        </x-admin::card>

        <div class="flex flex-col gap-5">
            <x-admin::card :title="__('admin::claims.review.payer')">
                @if ($facts['erased'])
                    <p class="text-body text-ink-2" data-test="payer-erased">{{ __('admin::claims.review.erased') }}</p>
                @else
                    <x-admin::description-list>
                        <dt class="{{ $dt }}">{{ __('admin::claims.review.address') }}</dt>
                        <dd class="{{ $dd }}" data-test="payer-address"><x-admin::ltr>{{ $facts['address'] }}</x-admin::ltr></dd>
                        <dt class="{{ $dt }}">{{ __('admin::claims.review.account_holds') }}</dt>
                        <dd class="{{ $dd }}" data-test="account-holds">{{ $facts['account_exists'] ? __('admin::claims.review.yes') : __('admin::claims.review.no') }}</dd>
                    </x-admin::description-list>
                @endif
            </x-admin::card>

            <x-admin::card :title="__('admin::claims.review.receipt')">
                @if ($facts['erased'])
                    <p class="text-body text-ink-2" data-test="receipt-erased">{{ __('admin::claims.review.receipt_erased') }}</p>
                @elseif ($facts['has_receipt'])
                    <div class="flex flex-col items-start gap-2.5">
                        <x-admin::button variant="secondary" icon="external" wire:click="getReceiptLink" data-test="receipt-link">{{ __('admin::claims.review.receipt_get') }}</x-admin::button>
                        @if ($receiptUrl !== '')
                            <x-admin::link :href="$receiptUrl" :external="true" data-test="receipt-open">{{ __('admin::claims.review.receipt_open') }}</x-admin::link>
                        @endif
                    </div>
                @else
                    <p class="text-body text-ink-2" data-test="receipt-none">{{ __('admin::claims.review.receipt_none') }}</p>
                @endif
            </x-admin::card>
        </div>
    </div>

    @if ($facts['duplicates'] !== [])
        <x-admin::card :title="__('admin::claims.review.same_reference')" :subtitle="__('admin::claims.review.same_reference_hint')" data-test="same-reference">
            <ul class="flex flex-col divide-y divide-line">
                @foreach ($facts['duplicates'] as $other)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2.5" wire:key="duplicate-{{ $other['id'] }}">
                        <a href="{{ route('admin.claims.show', ['claim' => $other['id']]) }}" class="rounded-badge text-accent hover:underline focus-ring-filled"><x-admin::ltr class="font-mono">{{ $other['reference'] }}</x-admin::ltr></a>
                        <span class="text-label text-ink-2">{{ __('admin::claims.status.'.$other['status']) }}</span>
                        <span class="text-label text-ink-2"><x-admin::instant :value="$other['created_at']" /></span>
                    </li>
                @endforeach
            </ul>
        </x-admin::card>
    @endif

    @if ($review['reviewed_at'] !== null || $review['code_status'] !== null)
        <x-admin::card :title="__('admin::claims.review.reviewed')" data-test="review-card">
            <x-admin::description-list>
                @if ($review['reviewed_at'] !== null)
                    <dt class="{{ $dt }}">{{ __('admin::claims.review.reviewer') }}</dt>
                    <dd class="{{ $dd }}" data-test="reviewer">{{ $reviewer ?? __('admin::claims.review.unknown_reviewer') }}</dd>
                    <dt class="{{ $dt }}">{{ __('admin::claims.review.reviewed_at') }}</dt>
                    <dd class="{{ $dd }}"><x-admin::instant :value="$review['reviewed_at']" /></dd>
                @endif
                @if ($review['reject_reason'] !== null)
                    <dt class="{{ $dt }}">{{ __('admin::claims.review.reject_reason') }}</dt>
                    <dd class="{{ $dd }}" data-test="reject-reason">{{ __('admin::claims.reject_reasons.'.$review['reject_reason']) }}</dd>
                @endif
                @if ($review['note'] !== null)
                    <dt class="{{ $dt }}">{{ __('admin::claims.review.note') }}</dt>
                    <dd class="{{ $dd }} whitespace-pre-line" data-test="review-note">{{ $review['note'] }}</dd>
                @endif
                @if ($review['code_status'] !== null)
                    <dt class="{{ $dt }}">{{ __('admin::claims.review.code') }}</dt>
                    <dd class="{{ $dd }}" data-test="code-status">{{ __('admin::claims.review.code_status.'.$review['code_status']) }}</dd>
                    <dt class="{{ $dt }}">{{ __('admin::claims.review.code_expires') }}</dt>
                    <dd class="{{ $dd }}"><x-admin::instant :value="$review['code_expires_at']" /></dd>
                @endif
            </x-admin::description-list>
        </x-admin::card>
    @endif

    <x-admin::dialog name="approve-claim" :title="__('admin::claims.approve_dialog.title')" :description="__('admin::claims.approve_dialog.body', ['plan' => $facts['plan']])" icon="check" tone="positive" size="sm">
        @if ($facts['duplicates'] !== [])
            <x-admin::alert tone="warning" data-test="approve-duplicate-warning">{{ __('admin::claims.approve_dialog.duplicate_warning') }}</x-admin::alert>
        @endif
        <x-slot:footer>
            <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
            <x-admin::button wire:click="confirmApprove" data-test="approve-confirm">{{ __('admin::claims.approve_dialog.confirm') }}</x-admin::button>
        </x-slot:footer>
    </x-admin::dialog>

    <x-admin::dialog name="reject-claim" :title="__('admin::claims.reject_dialog.title')" :description="__('admin::claims.reject_dialog.body')" icon="error" tone="danger" size="md">
        <div class="flex flex-col gap-4">
            <x-admin::radio-group
                name="rejectReason" style="plain" :legend="__('admin::claims.reject_dialog.reason')" :value="$rejectReason"
                :options="collect(['reference_not_found', 'amount_mismatch', 'duplicate', 'other'])->map(fn (string $reason): array => ['value' => $reason, 'label' => __('admin::claims.reject_reasons.'.$reason)])->all()"
                wire:model="rejectReason" data-test="reject-reason-options"
            />
            @error('rejectReason')<p class="text-caption font-semibold text-over-text" role="alert">{{ $message }}</p>@enderror
            <x-admin::textarea name="rejectNote" :label="__('admin::claims.reject_dialog.note')" :max="500" :value="$rejectNote" :error="$errors->first('rejectNote')" wire:model="rejectNote" data-test="reject-note" />
        </div>
        <x-slot:footer>
            <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
            <x-admin::button variant="danger" wire:click="confirmReject" data-test="reject-confirm">{{ __('admin::claims.reject_dialog.confirm') }}</x-admin::button>
        </x-slot:footer>
    </x-admin::dialog>

    <x-admin::factor-prompt />
    <x-admin::reason-prompt action-key="claim.revoke" tone="danger" :error="$errors->first('reason')" />
</div>
