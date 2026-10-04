<?php

namespace App\Modules\Billing\Domain\Claims;

use App\Modules\Billing\Domain\Refusals\InvalidReceiptImage;
use App\Modules\Shared\Domain\Errors\ErrorCode;

/**
 * A receipt screenshot that passed every check (spec 020, FR-012, R-4):
 * a declared type of the three, strict base64 with no line breaks, at most
 * 3 MiB once decoded, bytes carrying the declared type's signature and
 * parsing as an image of that type. Nothing here keeps or logs the bytes.
 */
final readonly class ReceiptImage
{
    public const MAX_BASE64_LENGTH = 4194304;

    private function __construct(
        public ReceiptMediaType $mediaType,
        public string $bytes,
    ) {}

    /**
     * @throws InvalidReceiptImage naming the offending member
     */
    public static function from(string $mediaType, string $base64, int $maxBytes = 3145728): self
    {
        $type = ReceiptMediaType::tryFrom($mediaType)
            ?? throw new InvalidReceiptImage('/screenshot/media_type', ErrorCode::InvalidEnumValue);

        if (strlen($base64) > self::MAX_BASE64_LENGTH) {
            throw new InvalidReceiptImage('/screenshot/data', ErrorCode::OutOfRange);
        }

        if ($base64 === '' || strlen($base64) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64) !== 1) {
            throw new InvalidReceiptImage('/screenshot/data', ErrorCode::InvalidType);
        }

        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidReceiptImage('/screenshot/data', ErrorCode::InvalidType);
        }

        if (strlen($bytes) > $maxBytes) {
            throw new InvalidReceiptImage('/screenshot/data', ErrorCode::OutOfRange);
        }

        if (! $type->hasSignature($bytes)) {
            throw new InvalidReceiptImage('/screenshot/data', ErrorCode::InvalidType);
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== $type->imageType() || $info[0] < 1 || $info[1] < 1 || $info[0] > 30000 || $info[1] > 30000) {
            throw new InvalidReceiptImage('/screenshot/data', ErrorCode::InvalidType);
        }

        return new self($type, $bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }
}
