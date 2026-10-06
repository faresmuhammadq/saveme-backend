@php
    $dt = 'col-span-1 border-t border-line py-2.5 text-ink-2';
    $dd = 'col-span-2 border-t border-line py-2.5 font-semibold text-ink';
    $facts = $account['facts'] ?? null;
    $state = $facts['state'] ?? null;
    $isActive = $state === 'active';
    $shortDate = fn (?string $instant): string => $instant === null ? '' : substr($instant, 0, 10);
    $ltrDate = fn (?string $instant): string => '<bdi dir="ltr">'.e($shortDate($instant)).'</bdi>';
    $stateTone = ['active' => 'positive', 'pending_deletion' => 'pending', 'purging' => 'pending', 'purged' => 'neutral'][$state] ?? 'neutral';
    $subscriptions = $account['access']['subscriptions'] ?? [];
    $governing = $account['access']['governing_subscription_id'] ?? null;
    $feature = function (string $key, mixed $value): string {
        $quota = in_array($key, ['sharing.max_members_per_wallet', 'wallets.max'], true);

        if ($quota) {
            return $value === null ? __('admin::accounts.plan.unlimited') : (string) $value;
        }

        return $value === true ? __('admin::accounts.plan.allowed') : __('admin::accounts.plan.not_allowed');
    };
@endphp

<div class="flex flex-col gap-5">
    @if ($waitMinutes !== null)
        <x-admin::alert tone="warning" data-test="lookup-budget">{{ __('admin::accounts.budget_spent', ['minutes' => $waitMinutes]) }}</x-admin::alert>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <x-admin::identifier :value="$facts['id']" :label="__('admin::accounts.page.account_id')" data-test="account-id" />
                <x-admin::pill :tone="$stateTone" data-test="state-{{ $state }}">
                    @if ($state === 'pending_deletion')
                        {!! __('admin::accounts.state.pending_deletion', ['date' => $ltrDate($facts['deletion_scheduled_for'])]) !!}
                    @else
                        {{ __('admin::accounts.state.'.$state) }}
                    @endif
                </x-admin::pill>
                @if ($state !== 'purged' && $state !== 'purging')
                    <x-admin::pill :tone="$facts['verified'] ? 'info' : 'neutral'" size="sm" data-test="verified">{{ $facts['verified'] ? __('admin::accounts.page.verified') : __('admin::accounts.page.unverified') }}</x-admin::pill>
                    <span class="text-label text-ink-2" data-test="created">{!! __('admin::accounts.page.created', ['date' => $ltrDate($facts['created_at'])]) !!}</span>
                @endif
            </div>
            @if ($isActive)
                <x-admin::button icon="check" wire:click="startGrant" data-test="grant">{{ __('admin::accounts.actions.grant') }}</x-admin::button>
            @endif
        </div>

        @if ($foundBy !== '')
            <p class="text-label text-ink-2" data-test="found-by">{!! __('admin::accounts.page.found_by', ['address' => '<bdi dir="ltr" class="font-semibold text-ink">'.e($foundBy).'</bdi>']) !!}</p>
        @endif

        @if ($state === 'purged')
            <x-admin::alert tone="info" data-test="purged-note">{{ __('admin::accounts.page.purged_note') }}</x-admin::alert>
        @elseif ($state === 'purging')
            <x-admin::alert tone="info" data-test="purging-note">{{ __('admin::accounts.page.purging_note') }}</x-admin::alert>
        @else
            <x-admin::alert tone="info" data-test="privacy-note">{{ __('admin::accounts.page.privacy_note') }}</x-admin::alert>

            <div class="grid gap-5 lg:grid-cols-2">
                <x-admin::card :title="__('admin::accounts.plan.title')" data-test="plan-card">
                    <x-admin::description-list>
                        <dt class="{{ $dt }}">{{ __('admin::accounts.plan.governing') }}</dt>
                        <dd class="{{ $dd }}" data-test="plan-name">{{ $account['plan']['name'][$locale] }}</dd>
                        @foreach ($account['plan']['features'] as $key => $value)
                            <dt class="{{ $dt }}">{{ __('admin::plans.capabilities.'.str_replace('.', '_', $key)) }}</dt>
                            <dd class="{{ $dd }}" data-test="feature-{{ $key }}"><bdi dir="auto">{{ $feature($key, $value) }}</bdi></dd>
                        @endforeach
                    </x-admin::description-list>
                </x-admin::card>

                <x-admin::card :title="__('admin::accounts.counts.title')" data-test="counts-card">
                    <x-admin::description-list>
                        <dt class="{{ $dt }}">{{ __('admin::accounts.counts.wallets_owned') }}</dt>
                        <dd class="{{ $dd }}" data-test="count-wallets-owned"><bdi dir="ltr">{{ $account['counts']['wallets_owned'] }}</bdi></dd>
                        <dt class="{{ $dt }}">{{ __('admin::accounts.counts.wallets_joined') }}</dt>
                        <dd class="{{ $dd }}" data-test="count-wallets-joined"><bdi dir="ltr">{{ $account['counts']['wallets_joined'] }}</bdi></dd>
                        <dt class="{{ $dt }}">{{ __('admin::accounts.counts.other_members') }}</dt>
                        <dd class="{{ $dd }}" data-test="count-other-members"><bdi dir="ltr">{{ $account['counts']['other_members'] }}</bdi></dd>
                        <dt class="{{ $dt }}">{{ __('admin::accounts.counts.devices') }}</dt>
                        <dd class="{{ $dd }}" data-test="count-devices"><bdi dir="ltr">{{ $facts['active_devices'] }}</bdi></dd>
                        <dt class="{{ $dt }}">{{ __('admin::accounts.counts.push_devices') }}</dt>
                        <dd class="{{ $dd }}" data-test="count-push-devices"><bdi dir="ltr">{{ $account['push_devices'] ?? 0 }}</bdi></dd>
                    </x-admin::description-list>
                </x-admin::card>
            </div>

            <x-admin::card :title="__('admin::accounts.subscriptions.title')" padding="none" data-test="subscriptions-card">
                @if ($subscriptions === [])
                    <p class="px-5 py-4 text-body text-ink-2" data-test="no-subscriptions">{{ __('admin::accounts.subscriptions.none') }}</p>
                @else
                    <x-admin::table
                        class="rounded-none border-0"
                        :caption="__('admin::accounts.subscriptions.title')"
                        :columns="[
                            ['key' => 'plan', 'label' => __('admin::accounts.subscriptions.columns.plan')],
                            ['key' => 'channel', 'label' => __('admin::accounts.subscriptions.columns.channel')],
                            ['key' => 'status', 'label' => __('admin::accounts.subscriptions.columns.status')],
                            ['key' => 'started', 'label' => __('admin::accounts.subscriptions.columns.started')],
                            ['key' => 'ends', 'label' => __('admin::accounts.subscriptions.columns.ends')],
                            ['key' => 'grace', 'label' => __('admin::accounts.subscriptions.columns.grace')],
                            ['key' => 'created', 'label' => __('admin::accounts.subscriptions.columns.created')],
                            ['key' => 'actions', 'label' => __('admin::accounts.subscriptions.columns.actions')],
                        ]"
                    >
                        @foreach ($subscriptions as $subscription)
                            <x-admin::table.row wire:key="subscription-{{ $subscription['id'] }}" data-test="subscription-row">
                                <x-admin::table.cell :strong="true">
                                    <span class="flex flex-wrap items-center gap-2">
                                        {{ $subscription['plan_name'][$locale] }}
                                        @if ($subscription['id'] === $governing)
                                            <x-admin::pill tone="positive" size="sm" data-test="governing">{{ __('admin::accounts.subscriptions.governing') }}</x-admin::pill>
                                        @endif
                                    </span>
                                </x-admin::table.cell>
                                <x-admin::table.cell>{{ __('admin::accounts.subscriptions.channels.'.$subscription['provider']) }}</x-admin::table.cell>
                                <x-admin::table.cell>{{ __('admin::accounts.subscriptions.statuses.'.$subscription['status']) }}</x-admin::table.cell>
                                <x-admin::table.cell :muted="true"><x-admin::instant :value="$subscription['period_start']" /></x-admin::table.cell>
                                <x-admin::table.cell :muted="true">
                                    @if ($subscription['period_end'] === null)
                                        {{ __('admin::accounts.subscriptions.no_end') }}
                                    @else
                                        <x-admin::instant :value="$subscription['period_end']" />
                                    @endif
                                </x-admin::table.cell>
                                <x-admin::table.cell :muted="true">
                                    @if ($subscription['grace_until'] === null)
                                        {{ __('admin::accounts.subscriptions.no_grace') }}
                                    @else
                                        <x-admin::instant :value="$subscription['grace_until']" />
                                    @endif
                                </x-admin::table.cell>
                                <x-admin::table.cell :muted="true"><x-admin::instant :value="$subscription['created_at']" /></x-admin::table.cell>
                                <x-admin::table.cell>
                                    <span class="flex flex-wrap gap-2">
                                        @if ($subscription['can_extend'])
                                            <x-admin::button variant="secondary" size="sm" wire:click="startExtend('{{ $subscription['id'] }}')" :aria-label="__('admin::accounts.actions.extend_label', ['plan' => $subscription['plan_name'][$locale]])" data-test="extend-{{ $subscription['id'] }}">{{ __('admin::accounts.actions.extend') }}</x-admin::button>
                                        @endif
                                        @if ($subscription['can_revoke'])
                                            <x-admin::button variant="danger" size="sm" wire:click="startRevoke('{{ $subscription['id'] }}')" :aria-label="__('admin::accounts.actions.revoke_label', ['plan' => $subscription['plan_name'][$locale]])" data-test="revoke-{{ $subscription['id'] }}">{{ __('admin::accounts.actions.revoke') }}</x-admin::button>
                                        @endif
                                    </span>
                                </x-admin::table.cell>
                            </x-admin::table.row>
                        @endforeach
                    </x-admin::table>
                @endif
            </x-admin::card>

            <p>
                <x-admin::link :href="route('admin.audit', ['subject_type' => 'user', 'subject_id' => $facts['id']])" data-test="audit-link">{{ __('admin::accounts.page.audit_link') }}</x-admin::link>
            </p>
        @endif
    @endif

    @if ($isActive)
        <x-admin::dialog name="grant-access" :title="__('admin::accounts.grant_dialog.title')" :description="__('admin::accounts.grant_dialog.body')" icon="check" size="md">
            <div class="flex flex-col gap-4">
                <x-admin::select name="grantPlan" :label="__('admin::accounts.grant_dialog.plan')" :options="$grantable" :value="$grantPlan" :error="$errors->first('grantPlan')" wire:model="grantPlan" data-test="grant-plan" />
                <x-admin::radio-group
                    name="grantDuration" style="plain" :legend="__('admin::accounts.grant_dialog.duration')" :value="$grantDuration"
                    :options="collect(['30', '90', '365', 'custom', 'life'])->map(fn (string $duration): array => ['value' => $duration, 'label' => __('admin::accounts.grant_dialog.durations.'.$duration)])->all()"
                    wire:model.live="grantDuration" data-test="grant-duration"
                />
                @error('grantDuration')<p class="text-caption font-semibold text-over-text" role="alert">{{ $message }}</p>@enderror
                @if ($grantDuration === 'custom')
                    <x-admin::number name="grantDays" :label="__('admin::accounts.grant_dialog.days')" :error="$errors->first('grantDays')" wire:model="grantDays" data-test="grant-days" />
                @endif
                <x-admin::textarea name="grantReason" :label="__('admin::accounts.grant_dialog.reason')" :hint="__('admin::accounts.reason_hint')" :max="500" :value="$grantReason" :error="$errors->first('grantReason')" wire:model="grantReason" data-test="grant-reason" />
            </div>
            <x-slot:footer>
                <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
                <x-admin::button wire:click="confirmGrant" data-test="grant-confirm">{{ __('admin::accounts.grant_dialog.confirm') }}</x-admin::button>
            </x-slot:footer>
        </x-admin::dialog>

        <x-admin::dialog name="extend-access" :title="__('admin::accounts.extend_dialog.title')" :description="__('admin::accounts.extend_dialog.body')" icon="check" size="md">
            <div class="flex flex-col gap-4">
                <x-admin::number name="extendDays" :label="__('admin::accounts.extend_dialog.days')" :error="$errors->first('extendDays')" wire:model="extendDays" data-test="extend-days" />
                <x-admin::textarea name="extendReason" :label="__('admin::accounts.extend_dialog.reason')" :hint="__('admin::accounts.reason_hint')" :max="500" :value="$extendReason" :error="$errors->first('extendReason')" wire:model="extendReason" data-test="extend-reason" />
            </div>
            <x-slot:footer>
                <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
                <x-admin::button wire:click="confirmExtend" data-test="extend-confirm">{{ __('admin::accounts.extend_dialog.confirm') }}</x-admin::button>
            </x-slot:footer>
        </x-admin::dialog>
    @endif

    @if ($state === 'active' || $state === 'pending_deletion')
        <x-admin::dialog name="revoke-access" :title="__('admin::accounts.revoke_dialog.title')" :description="__('admin::accounts.revoke_dialog.body')" icon="error" tone="danger" size="md">
            <div class="flex flex-col gap-4">
                <x-admin::textarea name="revokeReason" :label="__('admin::accounts.revoke_dialog.reason')" :hint="__('admin::accounts.reason_hint')" :max="500" :value="$revokeReason" :error="$errors->first('revokeReason')" wire:model="revokeReason" data-test="revoke-reason" />
            </div>
            <x-slot:footer>
                <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
                <x-admin::button variant="danger" wire:click="confirmRevoke" data-test="revoke-confirm">{{ __('admin::accounts.revoke_dialog.confirm') }}</x-admin::button>
            </x-slot:footer>
        </x-admin::dialog>
    @endif

    <x-admin::factor-prompt />
</div>
