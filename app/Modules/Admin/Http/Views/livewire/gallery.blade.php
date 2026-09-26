{{--
    Primitives gallery (spec 006, US4, FR-040): every primitive in every state
    listed in contracts/components.md, sample text only. Each specimen carries
    data-gallery-item="component.variant.state" for GalleryTest.
--}}
@php
    $section = 'flex flex-col gap-5 scroll-mt-6';
    $heading = 'text-h2 font-extrabold text-ink';
    $specimen = 'flex flex-col gap-2';
    $caption = 'text-overline font-bold tracking-widest text-ink-3 uppercase';
    $panel = 'rounded-card border border-line bg-surface p-6';
    $states = ['default', 'hover', 'focus', 'pressed', 'loading', 'disabled'];
    $swatches = [
        'canvas' => 'bg-canvas', 'surface' => 'bg-surface', 'sunken' => 'bg-sunken', 'sunken-2' => 'bg-sunken-2',
        'line' => 'bg-line', 'field' => 'bg-field', 'ink' => 'bg-ink', 'ink-2' => 'bg-ink-2', 'ink-3' => 'bg-ink-3',
        'accent' => 'bg-accent', 'accent-hover' => 'bg-accent-hover', 'accent-pressed' => 'bg-accent-pressed', 'accent-soft' => 'bg-accent-soft',
        'positive' => 'bg-positive', 'positive-text' => 'bg-positive-text', 'positive-soft' => 'bg-positive-soft',
        'pending' => 'bg-pending', 'pending-text' => 'bg-pending-text', 'pending-soft' => 'bg-pending-soft',
        'over' => 'bg-over', 'over-text' => 'bg-over-text', 'over-soft' => 'bg-over-soft',
    ];
    $columns = [
        ['key' => 'claim', 'label' => __('admin::gallery.table.claim')],
        ['key' => 'email', 'label' => __('admin::gallery.table.email')],
        ['key' => 'plan', 'label' => __('admin::gallery.table.plan')],
        ['key' => 'paid', 'label' => __('admin::gallery.table.paid'), 'align' => 'end'],
        ['key' => 'submitted', 'label' => __('admin::gallery.table.submitted'), 'sortable' => true],
        ['key' => 'status', 'label' => __('admin::gallery.table.status')],
    ];
    $rows = [
        ['id' => 'CLM-2046', 'email' => 'omar.farouk@example.com', 'plan' => __('admin::gallery.plans.plus_yearly'), 'minor' => 129000, 'at' => '2026-09-25T18:00:00Z', 'tone' => 'pending', 'status' => __('admin::gallery.claim_states.pending'), 'selected' => false, 'preview' => 'hover'],
        ['id' => 'CLM-2045', 'email' => 'nour.hassan@example.com', 'plan' => __('admin::gallery.plans.plus_monthly'), 'minor' => 14900, 'at' => '2026-09-25T17:42:00Z', 'tone' => 'pending', 'status' => __('admin::gallery.claim_states.pending'), 'selected' => true, 'preview' => null],
        ['id' => 'CLM-2041', 'email' => 'layla.m@example.com', 'plan' => __('admin::gallery.plans.plus_yearly'), 'minor' => 129000, 'at' => '2026-09-24T12:00:00Z', 'tone' => 'positive', 'status' => __('admin::gallery.claim_states.approved'), 'selected' => false, 'preview' => null],
        ['id' => 'CLM-2039', 'email' => 'y.samir@example.com', 'plan' => __('admin::gallery.plans.plus_monthly'), 'minor' => 14000, 'at' => '2026-09-24T09:30:00Z', 'tone' => 'over', 'status' => __('admin::gallery.claim_states.rejected'), 'selected' => false, 'preview' => null],
    ];
    $subscriptionStates = [
        ['positive', 'active'], ['info', 'trial'], ['pending', 'in_grace'], ['pending', 'on_hold'],
        ['neutral', 'paused'], ['neutral', 'canceled'], ['neutral', 'expired'], ['over', 'revoked'],
    ];
    $claimStates = [['pending', 'pending'], ['positive', 'approved'], ['over', 'rejected'], ['neutral', 'expired']];
@endphp

<div class="flex flex-col gap-12">
    <x-admin::alert tone="info" :title="__('admin::gallery.sample_notice_title')">{{ __('admin::gallery.sample_notice') }}</x-admin::alert>

    <x-admin::tabs style="pill" current="tokens" :label="__('admin::gallery.sections_label')" :items="collect(['tokens', 'actions', 'forms', 'data', 'feedback', 'navigation', 'values', 'reason'])->map(fn ($key) => ['key' => $key, 'label' => __('admin::gallery.sections.'.$key), 'href' => '#'.$key])->all()" />

    {{-- Tokens --}}
    <section id="tokens" class="{{ $section }}" aria-labelledby="tokens-title">
        <h2 id="tokens-title" class="{{ $heading }}">{{ __('admin::gallery.sections.tokens') }}</h2>
        <div class="{{ $panel }} grid grid-cols-6 gap-4">
            @foreach ($swatches as $name => $class)
                <div class="{{ $specimen }}" data-gallery-item="token.color.{{ $name }}">
                    <div class="h-14 rounded-tile border border-line {{ $class }}"></div>
                    <span class="font-mono text-caption text-ink-2" dir="ltr">{{ $name }}</span>
                </div>
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-5">
            <div class="{{ $panel }} flex flex-col gap-3" data-gallery-item="token.type.scale">
                <span class="text-numeral-xl font-extrabold"><x-admin::money :minor="128490000" currency="EGP" /></span>
                <span class="text-h1 font-extrabold">{{ __('admin::gallery.samples.h1') }}</span>
                <span class="text-h2 font-bold">{{ __('admin::gallery.samples.h2') }}</span>
                <span class="text-h3 font-bold">{{ __('admin::gallery.samples.h3') }}</span>
                <span class="text-body text-ink-2">{{ __('admin::gallery.samples.body') }}</span>
                <span class="text-label font-semibold">{{ __('admin::gallery.samples.label') }}</span>
                <span class="{{ $caption }}">{{ __('admin::gallery.samples.overline') }}</span>
                <span class="font-mono text-mono text-ink-2" dir="ltr">0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3</span>
            </div>
            <div class="{{ $panel }} flex flex-col gap-8">
                <div class="flex flex-wrap items-end gap-4">
                @foreach (['badge', 'control', 'tile', 'card', 'hero', 'full'] as $radius)
                    <div class="{{ $specimen }} items-center" data-gallery-item="token.radius.{{ $radius }}">
                        <div class="size-14 border-2 border-accent bg-accent-soft rounded-{{ $radius }}"></div>
                        <span class="text-caption text-ink-2">{{ $radius }}</span>
                    </div>
                @endforeach
                </div>
                <div class="flex flex-wrap items-end gap-4">
                <div class="{{ $specimen }}" data-gallery-item="token.elevation.flat"><div class="size-18 rounded-tile border border-line bg-surface"></div></div>
                <div class="{{ $specimen }}" data-gallery-item="token.elevation.raised"><div class="size-18 rounded-tile bg-surface shadow-raised"></div></div>
                <div class="{{ $specimen }}" data-gallery-item="token.elevation.overlay"><div class="size-18 rounded-tile bg-surface shadow-overlay"></div></div>
                <div class="{{ $specimen }}" data-gallery-item="token.focus.ring"><div class="size-18 rounded-tile border border-accent bg-surface ring-4 ring-ring"></div></div>
                </div>
            </div>
        </div>
    </section>

    {{-- Actions --}}
    <section id="actions" class="{{ $section }}" aria-labelledby="actions-title">
        <h2 id="actions-title" class="{{ $heading }}">{{ __('admin::gallery.sections.actions') }}</h2>
        <div class="{{ $panel }} grid grid-cols-7 items-center gap-4">
            <span></span>
            @foreach ($states as $state)<span class="{{ $caption }}">{{ __('admin::gallery.states.'.$state) }}</span>@endforeach
            @foreach (['primary', 'secondary', 'danger', 'ghost'] as $variant)
                <span class="text-body font-bold">{{ __('admin::gallery.variants.'.$variant) }}</span>
                @foreach ($states as $state)
                    <div data-gallery-item="button.{{ $variant }}.{{ $state }}">
                        <x-admin::button
                            :variant="$variant"
                            :preview-state="in_array($state, ['hover', 'pressed', 'focus'], true) ? $state : null"
                            :loading="$state === 'loading'"
                            :disabled="$state === 'disabled'"
                        >{{ __('admin::gallery.samples.button.'.$variant) }}</x-admin::button>
                    </div>
                @endforeach
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-5">
            <div class="{{ $panel }} flex flex-col gap-5">
                <div class="flex items-center gap-3.5">
                    @foreach (['sm', 'md', 'lg'] as $size)
                        <div data-gallery-item="button.size.{{ $size }}"><x-admin::button :size="$size">{{ __('admin::gallery.sizes.'.$size) }}</x-admin::button></div>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-3.5">
                    <div data-gallery-item="button.icon.leading"><x-admin::button icon="check">{{ __('admin::gallery.samples.approve_claim') }}</x-admin::button></div>
                    <div data-gallery-item="button.icon.secondary"><x-admin::button variant="secondary" icon="export">{{ __('admin::gallery.samples.export') }}</x-admin::button></div>
                    <div data-gallery-item="button.icon.trailing"><x-admin::button variant="ghost" icon-end="chevron-end">{{ __('admin::gallery.samples.view_user') }}</x-admin::button></div>
                </div>
                <div class="flex items-center gap-5">
                    <div data-gallery-item="segmented.default.selected"><x-admin::segmented name="gallery-range" value="day" :options="[['value' => 'day', 'label' => __('admin::gallery.samples.day')], ['value' => 'week', 'label' => __('admin::gallery.samples.week')], ['value' => 'month', 'label' => __('admin::gallery.samples.month')]]" /></div>
                    <div data-gallery-item="split-button.primary.default">
                        <x-admin::split-button :menu-label="__('admin::gallery.samples.more_grant_options')">
                            {{ __('admin::gallery.samples.grant') }}
                            <x-slot:menu>
                                <x-admin::menu.item icon="refresh">{{ __('admin::gallery.samples.extend') }}</x-admin::menu.item>
                            </x-slot:menu>
                        </x-admin::split-button>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    @foreach (['ghost', 'outline', 'filled', 'danger'] as $variant)
                        <div data-gallery-item="icon-button.{{ $variant }}.default"><x-admin::icon-button :variant="$variant" :icon="['ghost' => 'more', 'outline' => 'copy', 'filled' => 'add', 'danger' => 'trash'][$variant]" :label="__('admin::gallery.samples.icon.'.$variant)" /></div>
                    @endforeach
                    <div data-gallery-item="icon-button.ghost.hover"><x-admin::icon-button icon="more" :label="__('admin::gallery.samples.icon.ghost')" preview-state="hover" /></div>
                    <div data-gallery-item="icon-button.outline.focus"><x-admin::icon-button variant="outline" icon="filter" :label="__('admin::gallery.samples.filter')" preview-state="focus" /></div>
                    <div data-gallery-item="icon-button.ghost.disabled"><x-admin::icon-button icon="export" :label="__('admin::gallery.samples.export')" :disabled="true" /></div>
                </div>
                <div class="flex items-center gap-6">
                    <div data-gallery-item="link.default.default"><x-admin::link href="#">{{ __('admin::gallery.samples.link') }}</x-admin::link></div>
                    <div data-gallery-item="link.quiet.default"><x-admin::link href="#" variant="quiet">{{ __('admin::gallery.samples.link_quiet') }}</x-admin::link></div>
                    <div data-gallery-item="link.external.default"><x-admin::link href="https://play.google.com/console" :external="true">Play Console</x-admin::link></div>
                </div>
            </div>
            <div class="{{ $panel }} min-h-96" data-gallery-item="menu.default.open">
                <x-admin::menu :label="__('admin::gallery.samples.claim_actions')" :open="true">
                    <x-admin::menu.heading>CLM-2041</x-admin::menu.heading>
                    <x-admin::menu.item icon="check" shortcut="A" :opens-prompt="true">{{ __('admin::gallery.samples.approve') }}</x-admin::menu.item>
                    <x-admin::menu.item icon="search" shortcut="U" :highlighted="true">{{ __('admin::gallery.samples.open_user') }}</x-admin::menu.item>
                    <x-admin::menu.item icon="copy">{{ __('admin::gallery.samples.copy_claim_id') }}</x-admin::menu.item>
                    <x-admin::menu.item icon="refresh" :disabled="true" :disabled-reason="__('admin::gallery.samples.after_approval')">{{ __('admin::gallery.samples.resend_code') }}</x-admin::menu.item>
                    <x-admin::menu.separator />
                    <x-admin::menu.item icon="error" tone="danger" shortcut="R" :opens-prompt="true">{{ __('admin::gallery.samples.reject') }}</x-admin::menu.item>
                </x-admin::menu>
            </div>
        </div>
    </section>

    {{-- Forms --}}
    <section id="forms" class="{{ $section }}" aria-labelledby="forms-title">
        <h2 id="forms-title" class="{{ $heading }}">{{ __('admin::gallery.sections.forms') }}</h2>
        <div class="grid grid-cols-3 gap-5">
            <div class="{{ $panel }} flex flex-col gap-4.5">
                <div data-gallery-item="input.text.default"><x-admin::input name="g-email" type="email" :label="__('admin::gallery.fields.email')" placeholder="name@example.com" :hint="__('admin::gallery.fields.email_hint')" /></div>
                <div data-gallery-item="input.text.focus"><x-admin::input name="g-email-focus" type="email" :label="__('admin::gallery.fields.email')" value="layla@exa" preview-state="focus" /></div>
                <div data-gallery-item="input.text.invalid"><x-admin::input name="g-reference" :label="__('admin::gallery.fields.reference')" value="IP-88A" dir="ltr" :mono="true" :required="true" :error="__('admin::gallery.fields.reference_error')" /></div>
                <div data-gallery-item="input.text.disabled"><x-admin::input name="g-user-id" :label="__('admin::gallery.fields.user_id')" value="0192f4c1-7a3e-7b21" dir="ltr" :mono="true" disabled /></div>
                <div data-gallery-item="search.default.default"><x-admin::search name="g-search" :placeholder="__('admin::gallery.fields.search_placeholder')" /></div>
            </div>
            <div class="{{ $panel }} flex flex-col gap-4.5">
                <div data-gallery-item="money-input.default.filled"><x-admin::money-input name="g-price" currency="EGP" :value="14900" :label="__('admin::gallery.fields.monthly_price')" /></div>
                <div data-gallery-item="number.default.default"><x-admin::number name="g-members" :label="__('admin::gallery.fields.members')" value="5" /></div>
                <div data-gallery-item="textarea.default.counter"><x-admin::textarea name="g-reason" :label="__('admin::gallery.fields.reason')" :max="500" :value="__('admin::gallery.fields.reason_sample')" :hint="__('admin::gallery.fields.reason_hint')" /></div>
                <div data-gallery-item="select.default.closed"><x-admin::select name="g-plan-closed" :label="__('admin::gallery.fields.plan')" value="plus_yearly" :options="$planOptions" /></div>
                <div data-gallery-item="select.default.open" class="min-h-64"><x-admin::select name="g-plan-open" :label="__('admin::gallery.fields.plan')" value="plus_yearly" :options="$planOptions" :open="true" /></div>
            </div>
            <div class="{{ $panel }} flex flex-col gap-5">
                <div class="flex flex-col gap-3">
                    <div data-gallery-item="checkbox.default.unchecked"><x-admin::checkbox name="g-email-code" :label="__('admin::gallery.fields.email_code')" /></div>
                    <div data-gallery-item="checkbox.default.checked"><x-admin::checkbox name="g-push" :label="__('admin::gallery.fields.notify_push')" :checked="true" /></div>
                    <div data-gallery-item="checkbox.default.indeterminate"><x-admin::checkbox name="g-all" :label="__('admin::gallery.fields.select_page')" :indeterminate="true" /></div>
                    <div data-gallery-item="checkbox.default.disabled"><x-admin::checkbox name="g-refund" :label="__('admin::gallery.fields.refund')" :disabled="true" /></div>
                </div>
                <div class="flex flex-col gap-3.5">
                    <div data-gallery-item="toggle.default.on"><x-admin::toggle name="g-sharing" :label="__('admin::gallery.fields.sharing')" :description="__('admin::gallery.fields.feature_flag')" :checked="true" /></div>
                    <div data-gallery-item="toggle.default.off"><x-admin::toggle name="g-export" :label="__('admin::gallery.fields.export_csv')" :description="__('admin::gallery.fields.feature_flag')" /></div>
                    <div data-gallery-item="toggle.default.disabled"><x-admin::toggle name="g-bank" :label="__('admin::gallery.fields.bank_sync')" :description="__('admin::gallery.fields.out_of_scope')" :disabled="true" /></div>
                </div>
                <div data-gallery-item="radio-group.card.selected"><x-admin::radio-group name="g-period" :legend="__('admin::gallery.fields.period')" value="yearly" :options="[['value' => 'yearly', 'label' => __('admin::gallery.samples.yearly')], ['value' => 'monthly', 'label' => __('admin::gallery.samples.monthly')]]" /></div>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-5">
            <div class="{{ $panel }} flex gap-5">
                <div class="min-h-96 w-75" data-gallery-item="date-picker.default.open"><x-admin::date-picker name="g-starts-on" :label="__('admin::gallery.fields.period_start')" value="2026-09-25" :open="true" /></div>
                <div class="flex-1" data-gallery-item="instant-picker.default.filled"><x-admin::instant-picker name="g-revoke-at" :label="__('admin::gallery.fields.revoke_at')" value="2026-09-30T20:59:00Z" /></div>
            </div>
            <div class="flex flex-col gap-4">
                <div data-gallery-item="validation-summary.default.errors"><x-admin::validation-summary :errors="['g-sku' => __('admin::gallery.fields.sku_required'), 'g-yearly' => __('admin::gallery.fields.yearly_above_monthly')]" /></div>
                <div data-gallery-item="form-section.default.default">
                    <x-admin::form-section :title="__('admin::gallery.fields.pricing')" :description="__('admin::gallery.fields.pricing_description')">
                        <x-admin::money-input name="g-monthly" currency="EGP" :value="14900" :label="__('admin::gallery.fields.monthly')" />
                        <x-admin::money-input name="g-yearly" currency="EGP" :value="12900" :label="__('admin::gallery.fields.yearly')" :error="__('admin::gallery.fields.yearly_above_monthly')" />
                        <x-admin::input id="g-sku" name="g-sku" :label="__('admin::gallery.fields.sku')" dir="ltr" :mono="true" placeholder="plus_yearly_egp" :error="__('admin::gallery.fields.sku_required')" class="col-span-2" />
                        <x-slot:footer>
                            <x-admin::button variant="ghost">{{ __('admin::feedback.cancel') }}</x-admin::button>
                            <x-admin::button>{{ __('admin::gallery.samples.save_pricing') }}</x-admin::button>
                        </x-slot:footer>
                    </x-admin::form-section>
                </div>
            </div>
        </div>
    </section>

    {{-- Data --}}
    <section id="data" class="{{ $section }}" aria-labelledby="data-title">
        <h2 id="data-title" class="{{ $heading }}">{{ __('admin::gallery.sections.data') }}</h2>
        <div class="grid grid-cols-4 gap-5">
            <div class="col-span-2" data-gallery-item="stat-tile.hero.ring"><x-admin::stat-tile :label="__('admin::gallery.stats.revenue')" :hero="true" :ring="83" ring-label="25/30" :delta="__('admin::gallery.stats.revenue_delta')" delta-meaning="good" delta-direction="up"><x-admin::money :minor="61240000" currency="EGP" /></x-admin::stat-tile></div>
            <div data-gallery-item="stat-tile.delta.good"><x-admin::stat-tile :label="__('admin::gallery.stats.signups')" value="2,318" :delta="__('admin::gallery.stats.signups_delta')" delta-meaning="good" delta-direction="up" :bars="[40, 55, 48, 62, 70, 66, 100]" /></div>
            <div data-gallery-item="stat-tile.delta.bad"><x-admin::stat-tile :label="__('admin::gallery.stats.churned')" value="146" :delta="__('admin::gallery.stats.churned_delta')" delta-meaning="bad" delta-direction="up" :caption="__('admin::gallery.stats.churned_caption')" /></div>
            <div data-gallery-item="stat-tile.default.loading"><x-admin::stat-tile :label="__('admin::gallery.stats.revenue')" :loading="true" /></div>
            <div data-gallery-item="stat-tile.default.suppressed"><x-admin::stat-tile :label="__('admin::gallery.stats.lifetime_assiut')" :suppressed="true" /></div>
            <div data-gallery-item="stat-tile.default.sparkline"><x-admin::stat-tile :label="__('admin::gallery.stats.mau')" value="48,920" :bars="[70, 72, 75, 74, 78, 80, 84]" /></div>
            <div data-gallery-item="card.default.titled"><x-admin::card :title="__('admin::gallery.stats.card_title')" :subtitle="__('admin::gallery.stats.card_subtitle')">{{ __('admin::gallery.samples.body') }}</x-admin::card></div>
        </div>
        <div data-gallery-item="table.default.populated">
            <x-admin::table :columns="$columns" :caption="__('admin::gallery.table.caption')" :sort="['key' => 'submitted', 'direction' => 'desc']" :selectable="true" selection="some" :row-actions="true">
                <x-slot:toolbar>
                    <x-admin::search name="g-table-search" :placeholder="__('admin::gallery.table.search')" :shortcut="null" class="w-75" />
                    <x-admin::table.filters :applied="[['key' => 'status', 'label' => __('admin::gallery.table.filter_pending')]]">
                        <x-admin::table.add-filter>{{ __('admin::gallery.table.plan') }}</x-admin::table.add-filter>
                    </x-admin::table.filters>
                </x-slot:toolbar>
                @foreach ($rows as $row)
                    <x-admin::table.row :selected="$row['selected']" :select-value="$row['id']" :preview-state="$row['preview']" :data-gallery-item="'table.row.'.($row['selected'] ? 'selected' : ($row['preview'] ?? 'default'))">
                        <x-admin::table.cell :mono="true"><x-admin::ltr>{{ $row['id'] }}</x-admin::ltr></x-admin::table.cell>
                        <x-admin::table.cell :strong="true"><x-admin::ltr>{{ $row['email'] }}</x-admin::ltr></x-admin::table.cell>
                        <x-admin::table.cell :muted="true">{{ $row['plan'] }}</x-admin::table.cell>
                        <x-admin::table.cell align="end" :strong="true"><x-admin::money :minor="$row['minor']" currency="EGP" /></x-admin::table.cell>
                        <x-admin::table.cell :muted="true"><x-admin::instant :value="$row['at']" /></x-admin::table.cell>
                        <x-admin::table.cell><x-admin::pill :tone="$row['tone']">{{ $row['status'] }}</x-admin::pill></x-admin::table.cell>
                        <x-admin::table.cell><x-admin::icon-button icon="more" :label="__('admin::data.row_actions')" size="sm" /></x-admin::table.cell>
                    </x-admin::table.row>
                @endforeach
                <x-slot:footer>
                    <x-admin::table.pagination :summary="__('admin::gallery.table.summary')" next-url="#" />
                </x-slot:footer>
            </x-admin::table>
        </div>
        <div class="grid grid-cols-3 gap-5">
            <div data-gallery-item="table.default.empty"><x-admin::table :columns="array_slice($columns, 0, 2)" :empty="true" :empty-title="__('admin::gallery.table.empty_title')" empty-tone="positive"><x-slot:empty-body>{{ __('admin::gallery.table.empty_body') }}</x-slot:empty-body></x-admin::table></div>
            <div data-gallery-item="table.default.loading"><x-admin::table :columns="array_slice($columns, 0, 2)" :loading="true" :skeleton-rows="4" /></div>
            <div class="{{ $panel }}" data-gallery-item="description-list.default.default">
                <x-admin::description-list :items="[
                    ['term' => __('admin::gallery.table.plan'), 'value' => __('admin::gallery.plans.plus_yearly')],
                    ['term' => __('admin::gallery.samples.source'), 'value' => __('admin::gallery.samples.instapay_claim')],
                    ['term' => __('admin::gallery.samples.period'), 'value' => '2026-09-25 → 2027-09-24'],
                ]" />
            </div>
        </div>
    </section>

    {{-- Feedback and overlays --}}
    <section id="feedback" class="{{ $section }}" aria-labelledby="feedback-title">
        <h2 id="feedback-title" class="{{ $heading }}">{{ __('admin::gallery.sections.feedback') }}</h2>
        <div class="{{ $panel }} flex flex-col gap-4">
            <div class="flex flex-wrap gap-2">
                @foreach ($subscriptionStates as [$tone, $state])
                    <div data-gallery-item="pill.{{ $tone }}.{{ $state }}"><x-admin::pill :tone="$tone">{{ __('admin::gallery.subscription_states.'.$state) }}</x-admin::pill></div>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($claimStates as [$tone, $state])
                    <div data-gallery-item="pill.claim.{{ $state }}"><x-admin::pill :tone="$tone">{{ __('admin::gallery.claim_states.'.$state) }}</x-admin::pill></div>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <div data-gallery-item="badge.accent.default"><x-admin::badge tone="accent">Plus</x-admin::badge></div>
                <div data-gallery-item="badge.neutral.default"><x-admin::badge tone="neutral">{{ __('admin::gallery.plans.free') }}</x-admin::badge></div>
                <div data-gallery-item="badge.outline.default"><x-admin::badge tone="outline">{{ __('admin::gallery.roles.owner') }}</x-admin::badge></div>
                <div data-gallery-item="badge.subtle.default"><x-admin::badge tone="subtle">{{ __('admin::gallery.roles.editor') }}</x-admin::badge></div>
                <div data-gallery-item="badge.neutral.icon"><x-admin::badge tone="neutral" icon="device">Google Play</x-admin::badge></div>
                <div data-gallery-item="badge.count.default"><x-admin::badge tone="count">38</x-admin::badge></div>
                <div data-gallery-item="badge.danger-count.default"><x-admin::badge tone="danger-count">3</x-admin::badge></div>
                <div data-gallery-item="badge.dot.default"><x-admin::badge tone="dot" :label="__('admin::gallery.samples.new_notifications')" /></div>
                <div data-gallery-item="notification-list.default"><x-admin::notification-list :items="$sampleNotifications" :unread="1" name="gallery-notifications" /></div>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-5">
            <div class="flex flex-col gap-3">
                <div data-gallery-item="alert.info.default"><x-admin::alert tone="info" :title="__('admin::gallery.alerts.info_title')">{{ __('admin::gallery.alerts.info') }}</x-admin::alert></div>
                <div data-gallery-item="alert.success.action">
                    <x-admin::alert tone="success" :title="__('admin::gallery.alerts.success_title')">
                        {{ __('admin::gallery.alerts.success') }}
                        <x-slot:action><x-admin::link href="#">{{ __('admin::gallery.alerts.success_action') }}</x-admin::link></x-slot:action>
                    </x-admin::alert>
                </div>
                <div data-gallery-item="alert.warning.default"><x-admin::alert tone="warning" :title="__('admin::gallery.alerts.warning_title')">{{ __('admin::gallery.alerts.warning') }}</x-admin::alert></div>
                <div data-gallery-item="alert.error.dismissible"><x-admin::alert tone="error" :title="__('admin::gallery.alerts.error_title')" :dismissible="true">{{ __('admin::gallery.alerts.error') }}</x-admin::alert></div>
            </div>
            <div class="{{ $panel }} flex flex-col gap-4">
                <p class="text-body text-ink-2">{{ __('admin::gallery.toasts.explainer') }}</p>
                <div class="flex flex-wrap gap-2.5">
                    <div data-gallery-item="toast.success.undo"><x-admin::button variant="secondary" x-on:click="$dispatch('admin-toast', { tone: 'success', message: {{ \Illuminate\Support\Js::from(__('admin::gallery.toasts.saved')) }}, undo: 'gallery-undo' })">{{ __('admin::gallery.toasts.show_success') }}</x-admin::button></div>
                    <div data-gallery-item="toast.info.default"><x-admin::button variant="secondary" x-on:click="$dispatch('admin-toast', { tone: 'info', message: {{ \Illuminate\Support\Js::from(__('admin::gallery.toasts.copied')) }} })">{{ __('admin::gallery.toasts.show_info') }}</x-admin::button></div>
                    <div data-gallery-item="toast.error.sticky"><x-admin::button variant="secondary" x-on:click="$dispatch('admin-toast', { tone: 'error', message: {{ \Illuminate\Support\Js::from(__('admin::gallery.toasts.failed')) }} })">{{ __('admin::gallery.toasts.show_error') }}</x-admin::button></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div data-gallery-item="empty-state.positive.default"><x-admin::empty-state :title="__('admin::gallery.table.empty_title')" tone="positive" :level="3">{{ __('admin::gallery.table.empty_body') }}</x-admin::empty-state></div>
                    <div data-gallery-item="empty-state.search.action">
                        <x-admin::empty-state :title="__('admin::gallery.samples.no_match')" tone="search" :level="3">
                            {{ __('admin::gallery.samples.no_match_body') }}
                            <x-slot:action><x-admin::button variant="secondary" size="sm">{{ __('admin::forms.clear_search') }}</x-admin::button></x-slot:action>
                        </x-admin::empty-state>
                    </div>
                </div>
                <div class="flex items-center gap-4" data-gallery-item="skeleton.default.default">
                    <x-admin::skeleton shape="circle" />
                    <x-admin::skeleton :lines="2" class="flex-1" />
                </div>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-5">
            <div class="flex items-center justify-center rounded-card bg-scrim p-9" data-gallery-item="dialog.default.open">
                <x-admin::modal name="gallery-modal" :title="__('admin::gallery.dialogs.modal_title')" :description="__('admin::gallery.dialogs.modal_description')" :open="true" icon="info">
                    {{ __('admin::gallery.samples.body') }}
                    <x-slot:footer>
                        <x-admin::button variant="ghost">{{ __('admin::feedback.cancel') }}</x-admin::button>
                        <x-admin::button>{{ __('admin::feedback.confirm') }}</x-admin::button>
                    </x-slot:footer>
                </x-admin::modal>
            </div>
            <div class="flex items-center justify-center rounded-card bg-scrim p-9" data-gallery-item="confirm-dialog.danger.phrase">
                <x-admin::confirm-dialog name="gallery-revoke" :title="__('admin::gallery.dialogs.revoke_title')" :description="__('admin::gallery.dialogs.revoke_description')" confirm-phrase="REVOKE" :confirm-label="__('admin::gallery.dialogs.revoke')" :open="true" />
            </div>
            <div class="flex h-95 justify-end overflow-hidden rounded-card bg-scrim" data-gallery-item="slide-over.default.open">
                <x-admin::slide-over name="gallery-slide-over" title="omar.farouk@example.com" :open="true">
                    <x-admin::description-list :items="[
                        ['term' => __('admin::gallery.table.plan'), 'value' => __('admin::gallery.plans.free')],
                        ['term' => __('admin::gallery.samples.wallets'), 'value' => __('admin::gallery.samples.wallets_value')],
                    ]" />
                    <x-slot:footer><x-admin::button variant="secondary" class="w-full">{{ __('admin::gallery.samples.open_profile') }}</x-admin::button></x-slot:footer>
                </x-admin::slide-over>
            </div>
            <div class="flex items-center justify-center rounded-card bg-scrim p-9" data-gallery-item="reason-prompt.default.open">
                <x-admin::reason-prompt name="gallery-reason-static" action-key="gallery.approve" :presets="['reference_verified', 'amount_matches', 'screenshot_checked']" :attribution="__('admin::gallery.sample_operator')" :open="true" />
            </div>
        </div>
    </section>

    {{-- Navigation --}}
    <section id="navigation" class="{{ $section }}" aria-labelledby="navigation-title">
        <h2 id="navigation-title" class="{{ $heading }}">{{ __('admin::gallery.sections.navigation') }}</h2>
        <div class="{{ $panel }} grid grid-cols-2 gap-6">
            <div data-gallery-item="tabs.underline.current"><x-admin::tabs current="details" :label="__('admin::gallery.samples.claim')" :items="[['key' => 'details', 'label' => __('admin::gallery.tabs.details')], ['key' => 'receipt', 'label' => __('admin::gallery.tabs.receipt'), 'count' => 1], ['key' => 'account', 'label' => __('admin::gallery.tabs.account')], ['key' => 'history', 'label' => __('admin::gallery.tabs.history')]]" /></div>
            <div data-gallery-item="tabs.pill.current"><x-admin::tabs style="pill" current="7d" :label="__('admin::gallery.samples.range')" :items="[['key' => '7d', 'label' => __('admin::gallery.tabs.days_7')], ['key' => '30d', 'label' => __('admin::gallery.tabs.days_30')], ['key' => '12m', 'label' => __('admin::gallery.tabs.months_12')]]" /></div>
            <div data-gallery-item="breadcrumbs.default.default"><x-admin::breadcrumbs :items="[['label' => __('admin::gallery.samples.billing'), 'href' => '#'], ['label' => __('admin::gallery.nav.claims'), 'href' => '#'], ['label' => 'CLM-2046']]" /></div>
            <div data-gallery-item="pagination.numbered.middle"><x-admin::pagination :current="2" :last="9" url="#page-:page" /></div>
            <div class="col-span-2" data-gallery-item="pagination.cursor.middle"><x-admin::table.pagination :summary="__('admin::gallery.table.summary')" previous-url="#" next-url="#" /></div>
        </div>
    </section>

    {{-- Values --}}
    <section id="values" class="{{ $section }}" aria-labelledby="values-title">
        <h2 id="values-title" class="{{ $heading }}">{{ __('admin::gallery.sections.values') }}</h2>
        <div class="{{ $panel }}">
            <dl class="grid grid-cols-3 text-body">
                <dt class="border-t border-line py-3 text-ink-2">{{ __('admin::gallery.values.money') }}</dt>
                <dd class="col-span-2 border-t border-line py-3 text-h3 font-extrabold" data-gallery-item="money.default.default"><x-admin::money :minor="129000" currency="EGP" /> · <x-admin::money :minor="-4000" currency="EGP" /> · <x-admin::money :minor="0" currency="SAR" /></dd>
                <dt class="border-t border-line py-3 text-ink-2">{{ __('admin::gallery.values.compact') }}</dt>
                <dd class="col-span-2 border-t border-line py-3 text-h3 font-extrabold" data-gallery-item="money.compact.default"><x-admin::money :minor="3820000000" currency="EGP" :compact="true" /></dd>
                <dt class="border-t border-line py-3 text-ink-2">{{ __('admin::gallery.values.date') }}</dt>
                <dd class="col-span-2 border-t border-line py-3 font-semibold" data-gallery-item="date.default.default"><x-admin::date value="2026-09-25" /></dd>
                <dt class="border-t border-line py-3 text-ink-2">{{ __('admin::gallery.values.instant') }}</dt>
                <dd class="col-span-2 border-t border-line py-3 font-semibold" data-gallery-item="instant.default.default"><x-admin::instant value="2026-09-25T18:04:00Z" /></dd>
                <dt class="border-t border-line py-3 text-ink-2">{{ __('admin::gallery.values.relative') }}</dt>
                <dd class="col-span-2 border-t border-line py-3 font-semibold" data-gallery-item="relative-time.default.default"><x-admin::relative-time :value="now()->subMinutes(4)->utc()->toIso8601ZuluString()" /></dd>
                <dt class="border-t border-line py-3 text-ink-2">{{ __('admin::gallery.values.identifier') }}</dt>
                <dd class="col-span-2 border-t border-line py-3" data-gallery-item="identifier.default.default"><x-admin::identifier value="0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3" :label="__('admin::gallery.values.user_id')" /></dd>
            </dl>
        </div>
        <div class="{{ $panel }} grid grid-cols-10 gap-2.5">
            @foreach ($icons as $name)
                <div class="flex flex-col items-center gap-1.5 rounded-tile bg-sunken py-2.5" data-gallery-item="icon.{{ $name }}.default">
                    <x-admin::icon :name="$name" :size="20" />
                    <span class="font-mono text-caption text-ink-3" dir="ltr">{{ $name }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Reason prompt, live --}}
    <section id="reason" class="{{ $section }}" aria-labelledby="reason-title">
        <h2 id="reason-title" class="{{ $heading }}">{{ __('admin::gallery.sections.reason') }}</h2>
        <div class="{{ $panel }} flex items-center justify-between gap-4" data-gallery-item="reason-prompt.live.default">
            <p class="text-body text-ink-2">{{ __('admin::gallery.reason_demo') }}</p>
            <x-admin::button icon="check" wire:click="startSampleApproval">{{ __('admin::gallery.samples.approve') }}…</x-admin::button>
        </div>
        <x-admin::reason-prompt action-key="gallery.approve" :presets="['reference_verified', 'amount_matches']" :attribution="__('admin::gallery.sample_operator')" :error="$errors->first('reason')" />
    </section>
</div>
