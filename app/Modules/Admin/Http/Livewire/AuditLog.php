<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Audit\AuditReader;
use App\Modules\Identity\Application\Audit\AuditCursor;
use App\Modules\Identity\Application\Audit\AuditFilter;
use App\Modules\Identity\Application\Audit\AuditRow;
use App\Modules\Identity\Application\Audit\InvalidAuditFilter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * The audit log (spec 021, US3; FR-041 to FR-046): newest first, 50 an entry
 * page, keyset paging on Older and Newer, and filters that must be applied.
 * Every query the screen runs, first load, apply, older and newer, is one
 * recorded `audit_read` written by `AuditReader`; expanding a row happens in
 * the browser over detail already loaded and writes nothing. The screen has
 * no edit, delete or export path.
 */
class AuditLog extends BackOfficeComponent
{
    #[Url]
    public string $event = '';

    #[Url(as: 'actor_type')]
    public string $actorType = '';

    #[Url(as: 'actor_id')]
    public string $actorId = '';

    #[Url(as: 'subject_type')]
    public string $subjectType = '';

    #[Url(as: 'subject_id')]
    public string $subjectId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** @var array<string, string> the filters the listed rows were read with, as the form members */
    #[Locked]
    public array $applied = [];

    #[Locked]
    public string $olderCursor = '';

    #[Locked]
    public string $newerCursor = '';

    /** @var list<array{id: string, occurred_at: string, event: string, actor_type: string, actor_id: string, actor_name: string, subject_type: string, subject_id: string, subject_url: string, origin: string, request_id: string, detail: array<string, string>}> */
    public array $rows = [];

    public bool $loaded = false;

    public function mount(): void
    {
        $this->applyFilters(AuditReader::PAGE_FIRST);
    }

    public function apply(): void
    {
        $this->applyFilters(AuditReader::PAGE_FIRST);
    }

    public function clearFilters(): void
    {
        $this->event = $this->actorType = $this->actorId = $this->subjectType = $this->subjectId = $this->from = $this->to = '';
        $this->applyFilters(AuditReader::PAGE_FIRST);
    }

    public function older(): void
    {
        $this->step($this->olderCursor, AuditReader::PAGE_OLDER);
    }

    public function newer(): void
    {
        $this->step($this->newerCursor, AuditReader::PAGE_NEWER);
    }

    private function step(string $token, string $page): void
    {
        $cursor = $token === '' ? null : AuditCursor::decode($token);

        if ($cursor === null) {
            return;
        }

        $this->fill($this->applied);
        $this->applyFilters($page, $cursor);
    }

    /**
     * @return array<string, string>
     */
    private function form(): array
    {
        return [
            'event' => $this->event,
            'actorType' => $this->actorType,
            'actorId' => $this->actorId,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    private function applyFilters(string $page, ?AuditCursor $cursor = null): void
    {
        $this->resetErrorBag();

        try {
            $filter = AuditFilter::from([
                'event' => $this->event,
                'actor_type' => $this->actorType,
                'actor_id' => $this->actorId,
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'from' => $this->from,
                'to' => $this->to,
            ], new DateTimeZone(config('admin.timezone')), new DateTimeImmutable('now', new DateTimeZone('UTC')));
        } catch (InvalidAuditFilter $invalid) {
            foreach ($invalid->fields as $field => $reason) {
                $message = $reason === AuditFilter::REASON_MALFORMED ? (str_ends_with($field, '_id') ? 'id' : 'date') : $reason;
                $this->addError($this->property($field), __('admin::audit.errors.'.$message));
            }

            $this->rows = [];
            $this->olderCursor = $this->newerCursor = '';
            $this->loaded = false;

            return;
        }

        $view = app(AuditReader::class)->read($this->operatorId(), $filter, $cursor, $page);

        $this->fill([
            'event' => $filter->event ?? '',
            'actorType' => $filter->actorType ?? '',
            'actorId' => $filter->actorId ?? '',
            'subjectType' => $filter->subjectType ?? '',
            'subjectId' => $filter->subjectId ?? '',
            'from' => $filter->from,
            'to' => $filter->to,
        ]);
        $this->applied = $this->form();
        $this->olderCursor = $view->page->olderCursor?->encode() ?? '';
        $this->newerCursor = $view->page->newerCursor?->encode() ?? '';
        $this->rows = array_map(fn (AuditRow $row): array => $this->present($row, $view->operatorNames), $view->page->rows);
        $this->loaded = true;
    }

    private function property(string $field): string
    {
        return Str::camel($field);
    }

    /**
     * @param  array<string, string>  $operatorNames
     * @return array{id: string, occurred_at: string, event: string, actor_type: string, actor_id: string, actor_name: string, subject_type: string, subject_id: string, subject_url: string, origin: string, request_id: string, detail: array<string, string>}
     */
    private function present(AuditRow $row, array $operatorNames): array
    {
        return [
            'id' => $row->id,
            'occurred_at' => $row->occurredAt,
            'event' => $row->event,
            'actor_type' => $row->actorType,
            'actor_id' => $row->actorId ?? '',
            'actor_name' => $row->actorType === 'operator' ? ($operatorNames[$row->actorId ?? ''] ?? '') : '',
            'subject_type' => $row->subjectType,
            'subject_id' => $row->subjectId ?? '',
            'subject_url' => $this->subjectUrl($row),
            'origin' => $row->origin ?? '',
            'request_id' => $row->requestId ?? '',
            'detail' => array_map(
                static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                $row->detail,
            ),
        ];
    }

    private function subjectUrl(AuditRow $row): string
    {
        if ($row->subjectId === null || ! Str::isUuid($row->subjectId)) {
            return '';
        }

        return match ($row->subjectType) {
            'user' => route('admin.accounts.show', ['user' => $row->subjectId]),
            'payment_claim' => route('admin.claims.show', ['claim' => $row->subjectId]),
            'plan' => route('admin.plans.edit', ['plan' => $row->subjectId]),
            default => '',
        };
    }

    public function render(): View
    {
        return view('admin::livewire.audit-log', [
            'events' => AuditFilter::events(),
            'actorTypes' => AuditFilter::actorTypes(),
            'subjectTypes' => AuditFilter::subjectTypes(),
        ])->layout('admin::page', ['title' => __('admin::audit.title'), 'subtitle' => __('admin::audit.subtitle')]);
    }
}
