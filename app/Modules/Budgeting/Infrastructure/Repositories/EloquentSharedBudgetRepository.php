<?php

namespace App\Modules\Budgeting\Infrastructure\Repositories;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRole;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Budgeting\Infrastructure\Models\SharedBudget;
use App\Modules\Budgeting\Infrastructure\Models\SharedBudgetEntry;
use App\Modules\Budgeting\Infrastructure\Models\SharedBudgetLimit;
use App\Modules\Budgeting\Infrastructure\Models\SharedBudgetLine;
use App\Modules\Budgeting\Infrastructure\Models\SharedBudgetMember;
use App\Modules\Budgeting\Infrastructure\Models\SharedBudgetStartDay;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentSharedBudgetRepository implements SharedBudgetRepository
{
    public function find(string $id): ?SharedBudgetRecord
    {
        $budget = SharedBudget::query()->find($id);

        return $budget === null ? null : $this->withHistories(self::budget($budget));
    }

    public function findForUpdate(string $id): ?SharedBudgetRecord
    {
        $budget = SharedBudget::query()->whereKey($id)->lockForUpdate()->first();

        return $budget === null ? null : $this->withHistories(self::budget($budget));
    }

    public function identifierTaken(string $id): bool
    {
        return SharedBudget::query()->whereKey($id)->exists()
            || SharedBudgetLine::query()->whereKey($id)->exists()
            || Budget::query()->whereKey($id)->exists();
    }

    public function insert(string $id, string $ownerId, string $timezone, array $values): void
    {
        SharedBudget::query()->forceCreate([
            'id' => $id,
            'owner_id' => $ownerId,
            'timezone' => $timezone,
            ...$values,
            'version' => 1,
            'change_seq' => 1,
        ]);
    }

    public function update(string $id, array $columns): void
    {
        SharedBudget::query()->whereKey($id)->toBase()->update([
            ...$columns,
            'version' => DB::raw('version + 1'),
            'updated_at' => self::instant(),
        ]);
    }

    public function nextSeq(string $id): int
    {
        return (int) DB::scalar('update shared_budgets set change_seq = change_seq + 1 where id = ? returning change_seq', [$id]);
    }

    public function listForMember(string $userId, bool $descending, ?array $after, int $limit): array
    {
        $query = SharedBudget::query()
            ->whereNull('deleted_at')
            ->whereIn('id', SharedBudgetMember::query()->select('shared_budget_id')->where('user_id', $userId)->whereNull('deleted_at'));

        if ($after !== null) {
            $query->whereRaw('(created_at, id) '.($descending ? '<' : '>').' (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderBy('created_at', $descending ? 'desc' : 'asc')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->limit($limit + 1)
            ->get()
            ->map(fn (SharedBudget $budget): SharedBudgetRecord => $this->withHistories(self::budget($budget)))
            ->all());
    }

    public function sortValue(SharedBudgetRecord $budget): string
    {
        return $budget->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function activeMember(string $budgetId, string $userId): ?SharedBudgetMemberRecord
    {
        $member = SharedBudgetMember::query()->where('shared_budget_id', $budgetId)->where('user_id', $userId)->whereNull('deleted_at')->first();

        return $member === null ? null : self::member($member);
    }

    public function activeMembers(string $budgetId): array
    {
        return array_values(SharedBudgetMember::query()
            ->where('shared_budget_id', $budgetId)
            ->whereNull('deleted_at')
            ->orderByRaw("role = 'owner' desc")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedBudgetMember $member): SharedBudgetMemberRecord => self::member($member))
            ->all());
    }

    public function insertMember(string $budgetId, string $userId, SharedBudgetRole $role, int $changeSeq, int $memberChangeSeq): SharedBudgetMemberRecord
    {
        $member = SharedBudgetMember::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'shared_budget_id' => $budgetId,
            'user_id' => $userId,
            'role' => $role->value,
            'read_only' => false,
            'version' => 1,
            'change_seq' => $changeSeq,
            'member_change_seq' => $memberChangeSeq,
        ]);

        return self::member($member->refresh());
    }

    public function updateMember(string $memberId, array $columns, int $changeSeq, int $memberChangeSeq): void
    {
        SharedBudgetMember::query()->whereKey($memberId)->toBase()->update([
            ...$columns,
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'member_change_seq' => $memberChangeSeq,
            'updated_at' => self::instant(),
        ]);
    }

    public function liveBudgetIdsOf(string $userId): array
    {
        return array_values(SharedBudgetMember::query()->where('user_id', $userId)->whereNull('deleted_at')->orderBy('shared_budget_id')->pluck('shared_budget_id')->all());
    }

    public function ownedLiveBudgetIds(string $userId): array
    {
        return array_values(SharedBudget::query()->where('owner_id', $userId)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all());
    }

    public function findLine(string $lineId): ?SharedBudgetLineRecord
    {
        $line = SharedBudgetLine::query()->find($lineId);

        return $line === null ? null : $this->withLineHistory(self::line($line), $this->lineLimits($line->shared_budget_id));
    }

    public function insertLine(string $lineId, string $budgetId, array $values, int $changeSeq): void
    {
        SharedBudgetLine::query()->forceCreate([
            'id' => $lineId,
            'shared_budget_id' => $budgetId,
            ...$values,
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);
    }

    public function updateLine(string $lineId, array $columns, int $changeSeq): void
    {
        SharedBudgetLine::query()->whereKey($lineId)->toBase()->update([
            ...$columns,
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'updated_at' => self::instant(),
        ]);
    }

    public function liveLines(string $budgetId): array
    {
        $limits = $this->lineLimits($budgetId);

        return array_values(SharedBudgetLine::query()
            ->where('shared_budget_id', $budgetId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedBudgetLine $line): SharedBudgetLineRecord => $this->withLineHistory(self::line($line), $limits))
            ->all());
    }

    public function allLines(string $budgetId): array
    {
        $limits = $this->lineLimits($budgetId);

        return array_values(SharedBudgetLine::query()->where('shared_budget_id', $budgetId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (SharedBudgetLine $line): SharedBudgetLineRecord => $this->withLineHistory(self::line($line), $limits))->all());
    }

    public function lineNames(string $budgetId, string $categoryId): bool
    {
        return SharedBudgetLine::query()->where('shared_budget_id', $budgetId)->where('category_id', $categoryId)->exists();
    }

    public function categoryUsed(string $categoryId): bool
    {
        return SharedBudgetLine::query()->where('category_id', $categoryId)->exists();
    }

    public function categoriesUsedAmong(array $categoryIds): array
    {
        return array_values(SharedBudgetLine::query()->whereIn('category_id', $categoryIds)->distinct()->pluck('category_id')->all());
    }

    public function budgetIdsWithLiveLineFor(string $categoryId): array
    {
        return array_values(SharedBudgetLine::query()->where('category_id', $categoryId)->whereNull('deleted_at')->orderBy('shared_budget_id')->pluck('shared_budget_id')->all());
    }

    public function recordLimit(string $budgetId, ?string $lineId, ?int $limitMinor, string $appliesFrom, int $changeSeq): void
    {
        $stored = SharedBudgetLimit::query()
            ->where('shared_budget_id', $budgetId)
            ->where('line_id', $lineId)
            ->whereDate('applies_from', $appliesFrom)
            ->first();

        if ($stored !== null) {
            $stored->forceFill(['limit_minor' => $limitMinor, 'change_seq' => $changeSeq])->save();

            return;
        }

        SharedBudgetLimit::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'shared_budget_id' => $budgetId,
            'line_id' => $lineId,
            'limit_minor' => $limitMinor,
            'applies_from' => $appliesFrom,
            'change_seq' => $changeSeq,
        ]);
    }

    public function limitHistory(string $budgetId): array
    {
        return array_values(SharedBudgetLimit::query()
            ->where('shared_budget_id', $budgetId)
            ->orderBy('applies_from')
            ->get()
            ->map(fn (SharedBudgetLimit $limit): array => [
                'line_id' => $limit->line_id,
                'limit_minor' => $limit->limit_minor,
                'applies_from' => self::date($limit->getRawOriginal('applies_from')),
            ])
            ->all());
    }

    public function recordStartDay(string $budgetId, int $startDay, ?string $appliesFrom, int $changeSeq): void
    {
        $stored = SharedBudgetStartDay::query()->where('shared_budget_id', $budgetId)->where('applies_from', $appliesFrom)->first();

        if ($stored !== null) {
            $stored->forceFill(['start_day' => $startDay, 'change_seq' => $changeSeq])->save();

            return;
        }

        SharedBudgetStartDay::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'shared_budget_id' => $budgetId,
            'start_day' => $startDay,
            'applies_from' => $appliesFrom,
            'change_seq' => $changeSeq,
        ]);
    }

    public function startDayHistory(string $budgetId): array
    {
        return array_values(SharedBudgetStartDay::query()
            ->where('shared_budget_id', $budgetId)
            ->orderByRaw('applies_from asc nulls first')
            ->get()
            ->map(function (SharedBudgetStartDay $entry): array {
                $from = $entry->getRawOriginal('applies_from');

                return ['start_day' => $entry->start_day, 'applies_from' => is_string($from) ? self::date($from) : null];
            })
            ->all());
    }

    public function entryFor(string $budgetId, string $transactionId): ?SharedBudgetEntryRecord
    {
        $entry = SharedBudgetEntry::query()->where('shared_budget_id', $budgetId)->where('transaction_id', $transactionId)->first();

        return $entry === null ? null : self::entry($entry);
    }

    public function insertEntry(string $budgetId, string $transactionId, array $values, int $changeSeq): void
    {
        SharedBudgetEntry::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'shared_budget_id' => $budgetId,
            'transaction_id' => $transactionId,
            ...$values,
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);
    }

    public function updateEntry(string $entryId, array $columns, int $changeSeq): void
    {
        SharedBudgetEntry::query()->whereKey($entryId)->toBase()->update([
            ...$columns,
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'updated_at' => self::instant(),
        ]);
    }

    public function followingEntriesOf(string $budgetId, string $authorId): array
    {
        return array_values(SharedBudgetEntry::query()
            ->where('shared_budget_id', $budgetId)
            ->where('created_by', $authorId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedBudgetEntry $entry): SharedBudgetEntryRecord => self::entry($entry))
            ->all());
    }

    public function entriesAuthoredBy(string $userId, string $afterId, int $limit): array
    {
        return array_values(SharedBudgetEntry::query()
            ->where('created_by', $userId)
            ->when($afterId !== '', fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (SharedBudgetEntry $entry): SharedBudgetEntryRecord => self::entry($entry))
            ->all());
    }

    public function liveEntries(string $budgetId): array
    {
        return array_values(SharedBudgetEntry::query()
            ->where('shared_budget_id', $budgetId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedBudgetEntry $entry): SharedBudgetEntryRecord => self::entry($entry))
            ->all());
    }

    public function followingEntriesForTransactions(array $transactionIds): array
    {
        return array_values(SharedBudgetEntry::query()
            ->whereIn('transaction_id', $transactionIds)
            ->whereNull('deleted_at')
            ->whereNull('detached_at')
            ->orderBy('shared_budget_id')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedBudgetEntry $entry): SharedBudgetEntryRecord => self::entry($entry))
            ->all());
    }

    public function totals(string $budgetId, string $from, string $to): array
    {
        return array_values(SharedBudgetEntry::query()
            ->selectRaw('created_by, category_id, sum(amount_minor) as amount_minor')
            ->where('shared_budget_id', $budgetId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$from, $to])
            ->groupBy('created_by', 'category_id')
            ->orderBy('created_by')
            ->orderBy('category_id')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'created_by' => (string) $row->created_by,
                'category_id' => (string) $row->category_id,
                'amount_minor' => (int) $row->amount_minor,
            ])
            ->all());
    }

    public function membersChangedAfter(string $budgetId, int $after, int $limit): array
    {
        return array_values(SharedBudgetMember::query()->where('shared_budget_id', $budgetId)->where('change_seq', '>', $after)->orderBy('change_seq')->limit($limit)->get()
            ->map(fn (SharedBudgetMember $member): SharedBudgetMemberRecord => self::member($member))->all());
    }

    public function linesChangedAfter(string $budgetId, int $after, int $limit): array
    {
        $limits = $this->lineLimits($budgetId);

        return array_values(SharedBudgetLine::query()->where('shared_budget_id', $budgetId)->where('change_seq', '>', $after)->orderBy('change_seq')->limit($limit)->get()
            ->map(fn (SharedBudgetLine $line): SharedBudgetLineRecord => $this->withLineHistory(self::line($line), $limits))->all());
    }

    public function entriesChangedAfter(string $budgetId, int $after, int $limit): array
    {
        return array_values(SharedBudgetEntry::query()->where('shared_budget_id', $budgetId)->where('change_seq', '>', $after)->orderBy('change_seq')->limit($limit)->get()
            ->map(fn (SharedBudgetEntry $entry): SharedBudgetEntryRecord => self::entry($entry))->all());
    }

    public function membershipsChangedAfter(string $userId, int $after, int $limit): array
    {
        return array_values(SharedBudgetMember::query()->where('user_id', $userId)->where('member_change_seq', '>', $after)->orderBy('member_change_seq')->limit($limit)->get()
            ->map(fn (SharedBudgetMember $member): SharedBudgetMemberRecord => self::member($member))->all());
    }

    public function headsFor(string $userId, ?string $afterId, int $limit): array
    {
        return array_values(SharedBudget::query()
            ->whereNull('deleted_at')
            ->whereIn('id', SharedBudgetMember::query()->select('shared_budget_id')->where('user_id', $userId)->whereNull('deleted_at'))
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'change_seq', 'purged_through'])
            ->map(fn (SharedBudget $budget): array => ['shared_budget_id' => $budget->id, 'change_seq' => $budget->change_seq, 'purged_change_seq' => $budget->purged_through])
            ->all());
    }

    public function expiredTombstones(string $kind, DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $table = self::table($kind);
        $columns = $kind === 'members' ? 'id, shared_budget_id, user_id, change_seq, member_change_seq' : 'id, shared_budget_id, NULL::uuid as user_id, change_seq, NULL::bigint as member_change_seq';

        /** @var list<object{id: string, shared_budget_id: string, user_id: ?string, change_seq: int|string, member_change_seq: int|string|null}> $rows */
        $rows = DB::select("SELECT {$columns} FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at < ? AND id > ? ORDER BY id LIMIT ?", [$before->format('Y-m-d H:i:s.uP'), $afterId === '' ? '00000000-0000-0000-0000-000000000000' : $afterId, $limit]);

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'shared_budget_id' => (string) $row->shared_budget_id,
            'user_id' => $row->user_id === null ? null : (string) $row->user_id,
            'change_seq' => (int) $row->change_seq,
            'member_change_seq' => $row->member_change_seq === null ? null : (int) $row->member_change_seq,
        ], $rows);
    }

    public function deleteTombstones(string $kind, array $ids, DateTimeImmutable $before): int
    {
        return DB::table(self::table($kind))->whereIn('id', $ids)->whereNotNull('deleted_at')->where('deleted_at', '<', $before->format('Y-m-d H:i:s.uP'))->delete();
    }

    public function raiseHorizon(string $budgetId, int $position): void
    {
        DB::update('UPDATE shared_budgets SET purged_through = GREATEST(purged_through, ?) WHERE id = ?', [$position, $budgetId]);
    }

    public function expiredBudgets(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return array_values(SharedBudget::query()->whereNotNull('deleted_at')->where('deleted_at', '<', $before->format('Y-m-d H:i:s.uP'))
            ->when($afterId !== '', fn ($query) => $query->where('id', '>', $afterId))->orderBy('id')->limit($limit)->pluck('id')->all());
    }

    public function deleteBudget(string $budgetId): void
    {
        foreach (['shared_budget_entries', 'shared_budget_lines', 'shared_budget_limits', 'shared_budget_start_days', 'shared_budget_members'] as $table) {
            DB::table($table)->where('shared_budget_id', $budgetId)->delete();
        }

        DB::table('shared_budgets')->where('id', $budgetId)->delete();
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            'members' => 'shared_budget_members',
            'lines' => 'shared_budget_lines',
            default => 'shared_budget_entries',
        };
    }

    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable()->setTimezone(new DateTimeZone('UTC'));
    }

    private function withHistories(SharedBudgetRecord $budget): SharedBudgetRecord
    {
        $history = array_values(array_map(
            fn (array $row): array => ['applies_from' => $row['applies_from'], 'limit_minor' => $row['limit_minor']],
            array_filter($this->limitHistory($budget->id), fn (array $row): bool => $row['line_id'] === null),
        ));

        return new SharedBudgetRecord(
            $budget->id, $budget->ownerId, $budget->name, $budget->currency, $budget->startDay, $budget->timezone, $budget->limitMinor,
            $budget->archivedAt, $budget->version, $budget->changeSeq, $budget->purgedThrough, $budget->createdAt, $budget->updatedAt, $budget->deletedAt,
            $history, $this->startDayHistory($budget->id),
        );
    }

    /**
     * The limit history of every line of a budget, by line id.
     *
     * @return array<string, list<array{applies_from: string, limit_minor: ?int}>>
     */
    private function lineLimits(string $budgetId): array
    {
        $byLine = [];

        foreach ($this->limitHistory($budgetId) as $row) {
            if ($row['line_id'] !== null) {
                $byLine[$row['line_id']][] = ['applies_from' => $row['applies_from'], 'limit_minor' => $row['limit_minor']];
            }
        }

        return $byLine;
    }

    /**
     * @param  array<string, list<array{applies_from: string, limit_minor: ?int}>>  $limits
     */
    private function withLineHistory(SharedBudgetLineRecord $line, array $limits): SharedBudgetLineRecord
    {
        return new SharedBudgetLineRecord(
            $line->id, $line->sharedBudgetId, $line->categoryId, $line->limitMinor, $line->name, $line->icon, $line->color,
            $line->version, $line->changeSeq, $line->createdAt, $line->updatedAt, $line->deletedAt, $limits[$line->id] ?? [],
        );
    }

    private static function instant(): string
    {
        return now()->format('Y-m-d H:i:s.uP');
    }

    private static function date(string $stored): string
    {
        return substr($stored, 0, 10);
    }

    private static function budget(SharedBudget $row): SharedBudgetRecord
    {
        return new SharedBudgetRecord(
            $row->id, $row->owner_id, $row->name, trim($row->currency), $row->start_day, $row->timezone, $row->limit_minor,
            $row->archived_at, $row->version, $row->change_seq, $row->purged_through,
            $row->created_at, $row->updated_at, $row->deleted_at,
        );
    }

    private static function member(SharedBudgetMember $row): SharedBudgetMemberRecord
    {
        return new SharedBudgetMemberRecord(
            $row->id, $row->shared_budget_id, $row->user_id, SharedBudgetRole::from($row->role), $row->read_only,
            $row->version, $row->change_seq, $row->member_change_seq, $row->created_at, $row->updated_at, $row->deleted_at,
        );
    }

    private static function line(SharedBudgetLine $row): SharedBudgetLineRecord
    {
        return new SharedBudgetLineRecord(
            $row->id, $row->shared_budget_id, $row->category_id, $row->limit_minor, $row->name, $row->icon, $row->color,
            $row->version, $row->change_seq, $row->created_at, $row->updated_at, $row->deleted_at,
        );
    }

    private static function entry(SharedBudgetEntry $row): SharedBudgetEntryRecord
    {
        return new SharedBudgetEntryRecord(
            $row->id, $row->shared_budget_id, $row->transaction_id, $row->created_by, $row->category_id,
            self::date($row->getRawOriginal('date')), $row->amount_minor, $row->description, $row->detached_at,
            $row->version, $row->change_seq, $row->created_at, $row->updated_at, $row->deleted_at,
        );
    }
}
