<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Plans\PlanChanges;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use App\Modules\Admin\Http\Presenters\MoneyPresenter;
use App\Modules\Billing\Application\Plans\PlanAdminQuery;
use App\Modules\Billing\Domain\Plans\PlanChangeSet;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\NothingToSave;
use App\Modules\Billing\Domain\Refusals\PlanChangedElsewhere;
use App\Modules\Billing\Domain\Refusals\PlanEditInvalid;
use App\Modules\Billing\Domain\Refusals\PlanNotRetirable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Edits one plan (spec 021, US4; FR-032 to FR-038). The screen keeps the
 * stored plan (`plan`, with the edit `version`) and the operator's `draft`;
 * the unsaved bar and the review are the difference between them. Saving and
 * retiring each ask for a fresh second-factor proof and call `PlanChanges`
 * with the version that was loaded, so a plan changed meanwhile is refused
 * and nothing is written. The plan code is never loaded.
 *
 * Draft capability keys replace the dots of the capability name with
 * underscores (`sharing.enabled` is `sharing_enabled`), because a dot in a
 * property path means nesting.
 */
class PlanEditor extends BackOfficeComponent
{
    use RequiresRecentFactor;

    private const QUOTAS = ['sharing.max_members_per_wallet', 'wallets.max'];

    private const LOCKED_ON_DEFAULT = ['export.enabled', 'reports.advanced', 'wallets.max'];

    private const CURRENCIES = ['EGP', 'USD', 'EUR', 'SAR', 'AED'];

    #[Locked]
    public string $planId = '';

    /** @var array<string, mixed> the stored plan as loaded, with its edit version (not named `plan`: that is the route parameter) */
    public array $stored = [];

    /** @var array<string, mixed> name_en, name_ar, sort_order, price_on, price_amount, price_currency, flag, quota, unlimited */
    public array $draft = [];

    public bool $stale = false;

    public string $reason = '';

    public string $retireReason = '';

    public function mount(string $plan): void
    {
        abort_unless(Str::isUuid($plan), 404);

        $this->planId = $plan;
        $this->load();
    }

    public function discard(): void
    {
        $this->resetErrorBag();
        $this->fillDraft();
        $this->dispatch('admin-toast', tone: 'success', message: __('admin::plans.messages.discarded'));
    }

    public function reload(): void
    {
        $this->stale = false;
        $this->resetErrorBag();
        $this->load();
    }

    public function review(): void
    {
        $this->resetErrorBag();

        try {
            app(PlanAdminQuery::class)->preview($this->planId, $this->changeSet());
        } catch (PlanEditInvalid $invalid) {
            $this->showFieldErrors($invalid->fields);

            return;
        } catch (NothingToSave) {
            $this->dispatch('admin-toast', tone: 'info', message: __('admin::plans.messages.nothing_to_save'));

            return;
        }

        $this->reason = '';
        $this->dispatch('admin-dialog-open', name: 'review-plan');
    }

    public function confirmSave(): void
    {
        $this->resetErrorBag();
        $reason = $this->reasonOf($this->reason, 'reason');

        if ($reason === null) {
            return;
        }

        $this->dispatch('admin-dialog-close', name: 'review-plan');
        $this->requireRecentFactor('applySave', [$reason]);
    }

    public function startRetire(): void
    {
        abort_unless($this->canRetire(), 404);

        $this->resetErrorBag();
        $this->retireReason = '';
        $this->dispatch('admin-dialog-open', name: 'retire-plan');
    }

    public function confirmRetire(): void
    {
        abort_unless($this->canRetire(), 404);

        $this->resetErrorBag();
        $reason = $this->reasonOf($this->retireReason, 'retireReason');

        if ($reason === null) {
            return;
        }

        $this->dispatch('admin-dialog-close', name: 'retire-plan');
        $this->requireRecentFactor('applyRetire', [$reason]);
    }

    protected function applySave(string $reason): void
    {
        try {
            app(PlanChanges::class)->update($this->operatorId(), $this->planId, (string) $this->stored['version'], $this->changeSet(), $reason);
        } catch (PlanEditInvalid $invalid) {
            $this->showFieldErrors($invalid->fields);

            return;
        } catch (NothingToSave) {
            $this->dispatch('admin-toast', tone: 'info', message: __('admin::plans.messages.nothing_to_save'));

            return;
        } catch (PlanChangedElsewhere) {
            $this->markStale();

            return;
        } catch (InvalidReason) {
            $this->dispatch('admin-toast', tone: 'error', message: __('admin::plans.errors.invalid_reason'));

            return;
        }

        $this->load();
        $this->dispatch('admin-toast', tone: 'success', message: __('admin::plans.messages.saved'));
    }

    protected function applyRetire(string $reason): void
    {
        try {
            app(PlanChanges::class)->retire($this->operatorId(), $this->planId, (string) $this->stored['version'], $reason);
        } catch (PlanNotRetirable) {
            $this->dispatch('admin-toast', tone: 'error', message: __('admin::plans.errors.'.($this->stored['is_default'] ? 'default_retire' : 'not_retirable')));

            return;
        } catch (PlanChangedElsewhere) {
            $this->markStale();

            return;
        } catch (InvalidReason) {
            $this->dispatch('admin-toast', tone: 'error', message: __('admin::plans.errors.invalid_reason'));

            return;
        }

        $this->load();
        $this->dispatch('admin-toast', tone: 'success', message: __('admin::plans.messages.retired'));
    }

    private function load(): void
    {
        $view = app(PlanAdminQuery::class)->find($this->planId);
        abort_if($view === null, 404);

        $this->stored = $view->toArray();
        $this->fillDraft();
    }

    private function fillDraft(): void
    {
        $price = $this->stored['instapay_price'];
        $draft = [
            'name_en' => $this->stored['name']['en'],
            'name_ar' => $this->stored['name']['ar'],
            'sort_order' => (string) $this->stored['sort_order'],
            'price_on' => $price !== null,
            'price_amount' => $price === null ? '' : (string) $price['amount_minor'],
            'price_currency' => $price === null ? 'EGP' : $price['currency'],
            'flag' => [],
            'quota' => [],
            'unlimited' => [],
        ];

        foreach ($this->stored['features'] as $key => $value) {
            $safe = self::safe($key);

            if (in_array($key, self::QUOTAS, true)) {
                $draft['quota'][$safe] = $value === null ? '' : (string) $value;
                $draft['unlimited'][$safe] = $value === null;
            } else {
                $draft['flag'][$safe] = $value === true;
            }
        }

        $this->draft = $draft;
    }

    private function changeSet(): PlanChangeSet
    {
        $features = [];

        foreach (array_keys($this->stored['features']) as $key) {
            $key = (string) $key;
            $safe = self::safe($key);

            $features[$key] = in_array($key, self::QUOTAS, true)
                ? (($this->draft['unlimited'][$safe] ?? false) ? null : self::whole($this->draft['quota'][$safe] ?? ''))
                : (bool) ($this->draft['flag'][$safe] ?? false);
        }

        return PlanChangeSet::fromDraft([
            'name' => ['en' => (string) ($this->draft['name_en'] ?? ''), 'ar' => (string) ($this->draft['name_ar'] ?? '')],
            'sort_order' => self::whole($this->draft['sort_order'] ?? ''),
            'instapay_price' => ($this->draft['price_on'] ?? false)
                ? ['amount_minor' => self::whole($this->draft['price_amount'] ?? ''), 'currency' => (string) ($this->draft['price_currency'] ?? '')]
                : null,
            'features' => $features,
        ]);
    }

    /**
     * A whole number as an int, anything else as the raw text, so the rule
     * that rejects it lives in Billing and not here.
     */
    private static function whole(mixed $value): int|string
    {
        $text = trim((string) $value);

        return preg_match('/^\d{1,9}$/', $text) === 1 ? (int) $text : $text;
    }

    private static function safe(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    /**
     * @param  list<string>  $fields
     */
    private function showFieldErrors(array $fields): void
    {
        foreach ($fields as $field) {
            [$property, $message] = match (true) {
                $field === 'name.en' => ['draft.name_en', 'name'],
                $field === 'name.ar' => ['draft.name_ar', 'name'],
                $field === 'sort_order' => ['draft.sort_order', 'sort_order'],
                $field === 'prices.instapay' => ['draft.price_amount', 'price'],
                str_starts_with($field, 'features.') => $this->featureError(substr($field, 9)),
                default => ['draft', 'flag'],
            };

            $this->addError($property, __('admin::plans.errors.'.$message));
        }
    }

    /**
     * @return array{string, string}
     */
    private function featureError(string $key): array
    {
        return in_array($key, self::QUOTAS, true)
            ? ['draft.quota.'.self::safe($key), 'quota']
            : ['draft.flag.'.self::safe($key), 'flag'];
    }

    private function reasonOf(string $value, string $field): ?string
    {
        $reason = trim($value);
        $length = mb_strlen($reason);

        if ($length < 1 || $length > 500) {
            $this->addError($field, __('admin::plans.errors.invalid_reason'));

            return null;
        }

        return $reason;
    }

    private function markStale(): void
    {
        $this->stale = true;
        $this->dispatch('admin-toast', tone: 'error', message: __('admin::plans.stale.message'));
    }

    private function canRetire(): bool
    {
        return ($this->stored['status'] ?? null) === 'active' && ($this->stored['is_default'] ?? true) === false;
    }

    /**
     * The fields that differ from the stored plan, each with before and after as text.
     *
     * @return list<array{field: string, label: string, before: string, after: string}>
     */
    private function differences(string $locale): array
    {
        $rows = [];
        $money = app(MoneyPresenter::class);
        $draft = $this->draft;

        foreach (['name_en', 'name_ar'] as $field) {
            $before = $this->stored['name'][substr($field, 5)];
            $after = trim((string) ($draft[$field] ?? ''));

            if ($after !== $before) {
                $rows[] = ['field' => $field, 'label' => __('admin::plans.fields.'.$field), 'before' => $before, 'after' => $after];
            }
        }

        $order = trim((string) ($draft['sort_order'] ?? ''));
        if ($order !== (string) $this->stored['sort_order']) {
            $rows[] = ['field' => 'sort_order', 'label' => __('admin::plans.fields.sort_order'), 'before' => (string) $this->stored['sort_order'], 'after' => $order];
        }

        $stored = $this->stored['instapay_price'];
        $amount = trim((string) ($draft['price_amount'] ?? ''));
        $currency = (string) ($draft['price_currency'] ?? '');
        $format = fn (string $amount, string $currency): string => preg_match('/^\d{1,9}$/', $amount) === 1 ? $money->format((int) $amount, $currency, $locale) : $amount;
        $removed = __('admin::plans.values.removed');

        if (($draft['price_on'] ?? false) === false) {
            if ($stored !== null) {
                $rows[] = ['field' => 'instapay', 'label' => __('admin::plans.fields.instapay'), 'before' => $format((string) $stored['amount_minor'], $stored['currency']), 'after' => $removed];
            }
        } elseif ($stored === null || (string) $stored['amount_minor'] !== $amount || $stored['currency'] !== $currency) {
            $rows[] = [
                'field' => 'instapay',
                'label' => __('admin::plans.fields.instapay'),
                'before' => $stored === null ? $removed : $format((string) $stored['amount_minor'], $stored['currency']),
                'after' => $format($amount, $currency),
            ];
        }

        foreach ($this->stored['features'] as $key => $value) {
            $safe = self::safe($key);
            $after = in_array($key, self::QUOTAS, true)
                ? (($draft['unlimited'][$safe] ?? false) ? null : trim((string) ($draft['quota'][$safe] ?? '')))
                : (bool) ($draft['flag'][$safe] ?? false);

            if ($after !== ($value === null || is_bool($value) ? $value : (string) $value)) {
                $rows[] = ['field' => $key, 'label' => __('admin::plans.capabilities.'.$safe), 'before' => self::capabilityText($key, $value), 'after' => self::capabilityText($key, $after)];
            }
        }

        return $rows;
    }

    private static function capabilityText(string $key, mixed $value): string
    {
        if (in_array($key, self::QUOTAS, true)) {
            return $value === null ? __('admin::plans.values.unlimited') : (string) $value;
        }

        return $value === true ? __('admin::plans.values.on') : __('admin::plans.values.off');
    }

    public function render(): View
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $differences = $this->differences($locale);

        $before = $this->stored['features']['sharing.max_members_per_wallet'] ?? null;
        $after = ($this->draft['unlimited']['sharing_max_members_per_wallet'] ?? false) ? null : self::whole($this->draft['quota']['sharing_max_members_per_wallet'] ?? '');
        $lowered = is_int($after) && ($before === null || (is_int($before) && $after < $before));

        $currencies = array_values(array_unique([...self::CURRENCIES, (string) ($this->draft['price_currency'] ?? 'EGP')]));

        return view('admin::livewire.plan-editor', [
            'locale' => $locale,
            'differences' => $differences,
            'membersLowered' => $lowered,
            'canRetire' => $this->canRetire(),
            'currencies' => array_map(fn (string $code): array => ['value' => $code, 'label' => $code], $currencies),
            'lockedOnDefault' => array_map(self::safe(...), self::LOCKED_ON_DEFAULT),
            'quotaKeys' => array_map(self::safe(...), self::QUOTAS),
        ])->layout('admin::page', [
            'title' => __('admin::plans.editor.title', ['plan' => $this->stored['name'][$locale]]),
            'breadcrumbs' => [
                ['label' => __('admin::plans.title'), 'href' => route('admin.plans')],
                ['label' => $this->stored['name'][$locale]],
            ],
        ]);
    }
}
