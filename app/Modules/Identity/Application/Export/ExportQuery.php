<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Domain\Export\ExportRecord;
use App\Modules\Identity\Domain\Export\ExportStatus;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Refusals\ExportNotFound;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\URL;

/**
 * Reads a person's own exports (spec 022, FR-013, FR-022). A ready export
 * carries a link signed for 15 minutes, never past its expiry, minted per
 * read; another person's export is not found.
 */
final class ExportQuery
{
    public function __construct(private readonly ExportRepository $exports) {}

    /**
     * @throws ExportNotFound
     */
    public function get(string $userId, string $exportId): ExportView
    {
        return $this->present($this->exports->findFor($userId, $exportId) ?? throw new ExportNotFound);
    }

    /**
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<ExportView>, next: array{value: string, id: string}|null}
     */
    public function page(string $userId, ?array $after, int $limit): array
    {
        $rows = $this->exports->listFor($userId, $after, $limit);
        $page = array_slice($rows, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return [
            'items' => array_map(fn (ExportRecord $record): ExportView => $this->present($record), $page),
            'next' => count($rows) > $limit && $last !== null
                ? ['value' => $last->requestedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), 'id' => $last->id]
                : null,
        ];
    }

    public function present(ExportRecord $record): ExportView
    {
        $now = now()->toImmutable();

        if ($record->status === ExportStatus::Ready && $record->expiresAt !== null && $record->expiresAt <= $now) {
            // Past its expiry but not yet swept: it is no longer downloadable.
            return new ExportView(new ExportRecord(
                $record->id, $record->userId, $record->scope, $record->walletId, ExportStatus::Expired, null, null, $record->sizeBytes,
                $record->requestedAt, $record->startedAt, $record->completedAt, $record->expiresAt, $record->deliveredAt,
            ));
        }

        if ($record->status !== ExportStatus::Ready || $record->expiresAt === null) {
            return new ExportView($record);
        }

        $until = min($now->modify('+'.(int) config('identity.export_link_minutes').' minutes'), $record->expiresAt);

        return new ExportView($record, self::linkFor($record->id, $until), DateTimeImmutable::createFromInterface($until));
    }

    /**
     * A signed link to the archive of an export, valid until the instant.
     */
    public static function linkFor(string $exportId, DateTimeImmutable $until): string
    {
        return URL::temporarySignedRoute('identity.exports.file', $until, ['export' => $exportId]);
    }
}
