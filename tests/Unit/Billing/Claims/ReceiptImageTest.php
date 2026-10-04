<?php

use App\Modules\Billing\Domain\Claims\ReceiptImage;
use App\Modules\Billing\Domain\Claims\ReceiptMediaType;
use App\Modules\Billing\Domain\Refusals\InvalidReceiptImage;
use App\Modules\Shared\Domain\Errors\ErrorCode;

/*
 * A receipt screenshot (spec 020, FR-012, R-4).
 */

function receiptFault(string $mediaType, string $base64): array
{
    try {
        ReceiptImage::from($mediaType, $base64);
    } catch (InvalidReceiptImage $e) {
        return [$e->pointer, $e->fieldCode];
    }

    return [];
}

test('a real PNG, JPEG and WebP are accepted', function (string $type) {
    $image = ReceiptImage::from("image/{$type}", receiptBase64($type));

    expect($image->mediaType->value)->toBe("image/{$type}")
        ->and($image->bytes)->toBe(receiptBytes($type))
        ->and($image->size())->toBe(strlen(receiptBytes($type)));
})->with(['png', 'jpeg', 'webp']);

test('a media type outside the three is INVALID_ENUM_VALUE at /screenshot/media_type', function (string $type) {
    expect(receiptFault($type, receiptBase64()))->toBe(['/screenshot/media_type', ErrorCode::InvalidEnumValue]);
})->with(['image/gif', 'application/pdf', 'IMAGE/PNG', '']);

test('text that is not base64 is INVALID_TYPE', function (string $data) {
    expect(receiptFault('image/png', $data))->toBe(['/screenshot/data', ErrorCode::InvalidType]);
})->with([
    'not base64' => ['!!!not base64!!!'],
    'empty' => [''],
    'bad padding' => ['iVBORw0KGgo'],
    'url-safe alphabet' => ['iVBO-w0K_go='],
]);

test('base64 with line breaks or spaces is INVALID_TYPE', function () {
    $encoded = receiptBase64();

    expect(receiptFault('image/png', chunk_split($encoded, 16, "\n")))->toBe(['/screenshot/data', ErrorCode::InvalidType])
        ->and(receiptFault('image/png', substr($encoded, 0, 8).' '.substr($encoded, 8)))->toBe(['/screenshot/data', ErrorCode::InvalidType]);
});

test('bytes of another type than declared are INVALID_TYPE', function () {
    expect(receiptFault('image/png', receiptBase64('jpeg')))->toBe(['/screenshot/data', ErrorCode::InvalidType])
        ->and(receiptFault('image/webp', receiptBase64('png')))->toBe(['/screenshot/data', ErrorCode::InvalidType]);
});

test('a signature without a parseable image is INVALID_TYPE', function () {
    $broken = base64_encode(substr(receiptBytes('png'), 0, 12));
    $text = base64_encode("\x89PNG\r\n\x1A\nthis is not an image at all");

    expect(receiptFault('image/png', $broken))->toBe(['/screenshot/data', ErrorCode::InvalidType])
        ->and(receiptFault('image/png', $text))->toBe(['/screenshot/data', ErrorCode::InvalidType])
        ->and(receiptFault('image/png', base64_encode('plain text')))->toBe(['/screenshot/data', ErrorCode::InvalidType]);
});

test('exactly 3 MiB decoded is accepted and one byte more is OUT_OF_RANGE', function () {
    expect(ReceiptImage::from('image/png', receiptBase64('png', 3145728))->size())->toBe(3145728)
        ->and(receiptFault('image/png', receiptBase64('png', 3145729)))->toBe(['/screenshot/data', ErrorCode::OutOfRange]);
});

test('a string longer than the base64 limit is OUT_OF_RANGE without decoding', function () {
    expect(receiptFault('image/png', str_repeat('A', ReceiptImage::MAX_BASE64_LENGTH + 4)))->toBe(['/screenshot/data', ErrorCode::OutOfRange]);
});

test('each media type knows its signature', function () {
    expect(ReceiptMediaType::Png->hasSignature(receiptBytes('png')))->toBeTrue()
        ->and(ReceiptMediaType::Png->hasSignature(receiptBytes('jpeg')))->toBeFalse()
        ->and(ReceiptMediaType::Jpeg->hasSignature(receiptBytes('jpeg')))->toBeTrue()
        ->and(ReceiptMediaType::Webp->hasSignature(receiptBytes('webp')))->toBeTrue()
        ->and(ReceiptMediaType::Webp->hasSignature('RIFF'))->toBeFalse();
});
