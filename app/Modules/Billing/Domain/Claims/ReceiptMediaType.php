<?php

namespace App\Modules\Billing\Domain\Claims;

/**
 * The image types a receipt may be (spec 020, FR-012), each with the byte
 * signature and the image type the bytes must prove.
 */
enum ReceiptMediaType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';

    public function hasSignature(string $bytes): bool
    {
        return match ($this) {
            self::Jpeg => str_starts_with($bytes, "\xFF\xD8\xFF"),
            self::Png => str_starts_with($bytes, "\x89PNG\r\n\x1A\n") && substr($bytes, 12, 4) === 'IHDR',
            self::Webp => strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
        };
    }

    public function imageType(): int
    {
        return match ($this) {
            self::Jpeg => IMAGETYPE_JPEG,
            self::Png => IMAGETYPE_PNG,
            self::Webp => IMAGETYPE_WEBP,
        };
    }
}
