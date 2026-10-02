<?php

namespace App\Modules\Sync\Infrastructure;

use App\Modules\Sync\Domain\Ports\CursorSigner;
use RuntimeException;

/**
 * HMAC-SHA256 over the cursor payload, keyed with `sync.cursor_key` (the
 * application key when unset). The cursor keeps the first 16 bytes; the
 * comparison is constant time (spec 013, R-9).
 */
final class HmacCursorSigner implements CursorSigner
{
    public function __construct(private readonly string $key) {}

    public static function fromConfig(): self
    {
        $key = config('sync.cursor_key') ?: config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('No key to sign sync cursors with.');
        }

        return new self($key);
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key, true);
    }

    public function verify(string $payload, string $signature): bool
    {
        $expected = substr($this->sign($payload), 0, strlen($signature));

        return $signature !== '' && hash_equals($expected, $signature);
    }
}
