<?php

namespace App\Modules\Sync\Domain\Cursor;

use App\Modules\Sync\Domain\Ports\CursorSigner;
use App\Modules\Sync\Domain\Refusals\CursorInvalid;

/**
 * A position in one scope's feed, bound to the feed, the scope and the reader
 * it was issued to (spec 013, R-9): base64url of a compact JSON
 * `{v, f, s, r, p}` (plus `h` when non-zero) followed by a 16-byte signature.
 * The client holds it and treats it as opaque; the server stores nothing.
 *
 * `h` is the scope's purge horizon when the read that issued the cursor
 * began (spec 017, FR-022). A read from the start that began after a purge
 * pages through the horizon: the cursor expires only when the horizon has
 * moved past both its position and `h`. A cursor without `h` is read as 0,
 * which is the rule before spec 017.
 */
final readonly class SyncCursor
{
    private const VERSION = 1;

    private const SIGNATURE_BYTES = 16;

    public function __construct(
        public FeedKind $feed,
        public string $scopeId,
        public string $readerId,
        public int $position,
        public int $horizon = 0,
    ) {}

    public function encode(CursorSigner $signer): string
    {
        $payload = (string) json_encode(['v' => self::VERSION, 'f' => $this->feed->value, 's' => $this->scopeId, 'r' => $this->readerId, 'p' => $this->position, ...($this->horizon > 0 ? ['h' => $this->horizon] : [])]);

        return rtrim(strtr(base64_encode($payload.substr($signer->sign($payload), 0, self::SIGNATURE_BYTES)), '+/', '-_'), '=');
    }

    /**
     * @throws CursorInvalid with the reason the cursor was refused
     */
    public static function decode(string $cursor, CursorSigner $signer, FeedKind $feed, string $scopeId, string $readerId): self
    {
        $raw = preg_match('/^[A-Za-z0-9_-]+$/', $cursor) === 1 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;

        if ($raw === false || strlen($raw) <= self::SIGNATURE_BYTES) {
            throw new CursorInvalid(CursorRefusal::Malformed);
        }

        $payload = substr($raw, 0, -self::SIGNATURE_BYTES);
        $signature = substr($raw, -self::SIGNATURE_BYTES);

        if (! $signer->verify($payload, $signature)) {
            throw new CursorInvalid(CursorRefusal::BadSignature);
        }

        $body = json_decode($payload, true);

        if (! is_array($body)) {
            throw new CursorInvalid(CursorRefusal::Malformed);
        }

        if (($body['v'] ?? null) !== self::VERSION) {
            throw new CursorInvalid(CursorRefusal::UnknownVersion);
        }

        $kind = is_string($body['f'] ?? null) ? FeedKind::tryFrom($body['f']) : null;
        $scope = $body['s'] ?? null;
        $reader = $body['r'] ?? null;
        $position = $body['p'] ?? null;
        $horizon = $body['h'] ?? 0;

        if ($kind === null || ! is_string($scope) || ! is_string($reader) || ! is_int($position) || $position < 0 || ! is_int($horizon) || $horizon < 0) {
            throw new CursorInvalid(CursorRefusal::Malformed);
        }

        if ($kind !== $feed) {
            throw new CursorInvalid(CursorRefusal::WrongFeed);
        }

        if ($scope !== $scopeId) {
            throw new CursorInvalid(CursorRefusal::WrongScope);
        }

        if ($reader !== $readerId) {
            throw new CursorInvalid(CursorRefusal::WrongReader);
        }

        return new self($kind, $scope, $reader, $position, $horizon);
    }
}
