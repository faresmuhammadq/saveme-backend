<?php

namespace App\Modules\Billing\Domain\Idempotency;

/**
 * What the store says about an Idempotency-Key (spec 020, R-2): the request
 * is new and may run (`Started`), the same request already finished and its
 * answer is replayed (`Replay`), the key belongs to a different request
 * (`Reused`), or the original is still running (`InProgress`).
 */
final readonly class IdempotencyOutcome
{
    public const STARTED = 'started';

    public const REPLAY = 'replay';

    public const REUSED = 'reused';

    public const IN_PROGRESS = 'in_progress';

    /**
     * @param  self::STARTED|self::REPLAY|self::REUSED|self::IN_PROGRESS  $kind
     * @param  string|null  $body  the stored answer, as JSON text, so `{}` stays `{}`
     */
    private function __construct(
        public string $kind,
        public ?int $status = null,
        public ?string $body = null,
    ) {}

    public static function started(): self
    {
        return new self(self::STARTED);
    }

    /**
     * @param  string  $body  JSON text
     */
    public static function replay(int $status, string $body): self
    {
        return new self(self::REPLAY, $status, $body);
    }

    public static function reused(): self
    {
        return new self(self::REUSED);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }
}
