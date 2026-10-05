<?php

namespace App\Modules\Identity\Application\Audit;

use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * What the audit log is narrowed to (spec 021, FR-042): the fixed event,
 * actor and subject vocabularies, exact ids, and a calendar-date range in the
 * operator's timezone (default the last 7 days, at most 400). The range is
 * held as UTC bounds, `fromUtc` inclusive and `untilUtc` exclusive.
 */
final readonly class AuditFilter
{
    public const MAX_DAYS = 400;

    public const DEFAULT_DAYS = 7;

    public const REASON_UNKNOWN = 'unknown';

    public const REASON_MALFORMED = 'malformed';

    public const REASON_TOO_LONG = 'range_too_long';

    public const REASON_INVERTED = 'range_inverted';

    private function __construct(
        public ?string $event,
        public ?string $actorType,
        public ?string $actorId,
        public ?string $subjectType,
        public ?string $subjectId,
        public string $from,
        public string $to,
        public DateTimeImmutable $fromUtc,
        public DateTimeImmutable $untilUtc,
    ) {}

    /**
     * @param  array<string, mixed>  $input  event, actor_type, actor_id, subject_type, subject_id, from, to (dates YYYY-MM-DD); blank members are absent
     *
     * @throws InvalidAuditFilter
     */
    public static function from(array $input, DateTimeZone $operatorZone, DateTimeImmutable $now): self
    {
        $errors = [];
        $value = static function (string $key) use ($input): ?string {
            $given = $input[$key] ?? null;

            return is_string($given) && trim($given) !== '' ? trim($given) : null;
        };

        $event = $value('event');
        $actorType = $value('actor_type');
        $subjectType = $value('subject_type');

        if ($event !== null && AuditEvent::tryFrom($event) === null) {
            $errors['event'] = self::REASON_UNKNOWN;
        }

        if ($actorType !== null && ActorType::tryFrom($actorType) === null) {
            $errors['actor_type'] = self::REASON_UNKNOWN;
        }

        if ($subjectType !== null && SubjectType::tryFrom($subjectType) === null) {
            $errors['subject_type'] = self::REASON_UNKNOWN;
        }

        $actorId = self::id($value('actor_id'), 'actor_id', $errors);
        $subjectId = self::id($value('subject_id'), 'subject_id', $errors);

        $today = $now->setTimezone($operatorZone)->setTime(0, 0);
        $toGiven = $value('to');
        $fromGiven = $value('from');
        $to = $toGiven === null ? $today : self::day($toGiven, $operatorZone);
        $from = $fromGiven === null ? $to?->modify('-'.(self::DEFAULT_DAYS - 1).' days') : self::day($fromGiven, $operatorZone);

        if ($to === null) {
            $errors['to'] = self::REASON_MALFORMED;
        }

        if ($from === null && $fromGiven !== null) {
            $errors['from'] = self::REASON_MALFORMED;
        }

        if ($from !== null && $to !== null) {
            if ($to < $from) {
                $errors['to'] = self::REASON_INVERTED;
            } elseif ($from->diff($to)->days > self::MAX_DAYS) {
                $errors['from'] = self::REASON_TOO_LONG;
            }
        }

        if ($errors !== [] || $from === null || $to === null) {
            throw new InvalidAuditFilter($errors);
        }

        $utc = new DateTimeZone('UTC');

        return new self(
            $event,
            $actorType,
            $actorId,
            $subjectType,
            $subjectId,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $from->setTimezone($utc),
            $to->modify('+1 day')->setTimezone($utc),
        );
    }

    /**
     * The scalars an `audit_read` entry records: the range always, the rest when given.
     *
     * @return array<string, string>
     */
    public function toDetail(): array
    {
        return array_filter([
            'event' => $this->event,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'from' => $this->from,
            'to' => $this->to,
        ], static fn (?string $member): bool => $member !== null);
    }

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->value, AuditEvent::cases());
    }

    /**
     * @return list<string>
     */
    public static function actorTypes(): array
    {
        return array_map(static fn (ActorType $type): string => $type->value, ActorType::cases());
    }

    /**
     * @return list<string>
     */
    public static function subjectTypes(): array
    {
        return array_map(static fn (SubjectType $type): string => $type->value, SubjectType::cases());
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function id(?string $id, string $field, array &$errors): ?string
    {
        if ($id === null) {
            return null;
        }

        if (! Str::isUuid($id)) {
            $errors[$field] = self::REASON_MALFORMED;

            return null;
        }

        return strtolower($id);
    }

    private static function day(string $date, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
