<?php

namespace App\Modules\Identity\Domain\Sessions;

use DateTimeImmutable;

/**
 * A Device Session as the application sees it: never the credential hash.
 */
final readonly class SessionRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $deviceId,
        public string $deviceName,
        public string $platform,
        public string $appVersion,
        public SessionState $state,
        public ?EndReason $endReason,
        public DateTimeImmutable $signedInAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ?DateTimeImmutable $endedAt,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
