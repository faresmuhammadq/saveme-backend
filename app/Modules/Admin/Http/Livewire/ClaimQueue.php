<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Lookup\LookupBudget;
use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use App\Modules\Billing\Application\Claims\ClaimFilter;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Application\Claims\ClaimSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * The InstaPay claim queue (spec 021, US1; FR-015): four tabs, 25 a page with
 * cursor paging, and a search by exact reference or exact address. The list
 * carries no address; an address search spends one unit of the operator's
 * lookup budget each time it runs a query (FR-061), and is not a recorded
 * read because nothing address-bearing comes back.
 */
class ClaimQueue extends BackOfficeComponent
{
    public const PAGE_SIZE = 25;

    #[Url]
    public string $status = 'submitted';

    #[Url]
    public string $q = '';

    #[Url]
    public string $after = '';

    public string $draft = '';

    /** @var list<string> cursors of the pages before this one */
    public array $history = [];

    /** @var list<array{id: string, reference: string, plan: string, amount_minor: int, currency: string, created_at: string, expires_at: string, duplicates: int}> */
    public array $rows = [];

    public string $next = '';

    public int $awaiting = 0;

    public ?int $budgetMinutes = null;

    public function mount(): void
    {
        if (ClaimFilter::tryFrom($this->status) === null) {
            $this->status = ClaimFilter::Submitted->value;
        }

        $this->q = trim($this->q);
        $this->draft = $this->q;
        $this->load();
    }

    public function setStatus(string $status): void
    {
        $this->status = (ClaimFilter::tryFrom($status) ?? ClaimFilter::Submitted)->value;
        $this->after = '';
        $this->history = [];
        $this->load();
    }

    public function search(): void
    {
        $this->q = trim($this->draft);
        $this->after = '';
        $this->history = [];
        $this->load();
    }

    public function clearSearch(): void
    {
        $this->draft = '';
        $this->search();
    }

    public function older(): void
    {
        if ($this->next === '') {
            return;
        }

        $this->history[] = $this->after;
        $this->after = $this->next;
        $this->load();
    }

    public function newer(): void
    {
        $this->after = $this->history === [] ? '' : (string) array_pop($this->history);
        $this->load();
    }

    private function load(): void
    {
        $claims = app(ClaimQuery::class);
        $this->budgetMinutes = null;
        $this->awaiting = $claims->awaitingSummary()['count'];
        $search = $this->searchTerm($claims);

        if ($search === false) {
            $this->rows = [];
            $this->next = '';

            return;
        }

        $page = $claims->page(ClaimFilter::from($this->status), $search, $this->after === '' ? null : $this->after, self::PAGE_SIZE);
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        $this->rows = array_map(fn (ClaimSummary $claim): array => [
            'id' => $claim->id,
            'reference' => $claim->reference,
            'plan' => $claim->planName[$locale] ?? '',
            'amount_minor' => $claim->amountMinor,
            'currency' => $claim->currency,
            'created_at' => $claim->createdAt->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $claim->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'duplicates' => $claim->duplicateCount,
        ], $page->items);
        $this->next = $page->next ?? '';
    }

    /**
     * The search the query takes, null for none, or false when the term cannot match
     * anything (a malformed address) or the lookup budget is spent.
     *
     * @return array{reference?: string, lookup?: string}|null|false
     */
    private function searchTerm(ClaimQuery $claims): array|null|false
    {
        if ($this->q === '') {
            return null;
        }

        if (! str_contains($this->q, '@')) {
            return ['reference' => $this->q];
        }

        $lookup = $claims->lookupFor($this->q);

        if ($lookup === null) {
            return false;
        }

        try {
            app(LookupBudget::class)->spend($this->operatorId());
        } catch (LookupBudgetSpent $spent) {
            $this->budgetMinutes = $spent->minutes;

            return false;
        }

        return ['lookup' => $lookup];
    }

    public function render(): View
    {
        return view('admin::livewire.claim-queue')
            ->layout('admin::page', ['title' => __('admin::claims.title'), 'subtitle' => __('admin::claims.subtitle')]);
    }
}
