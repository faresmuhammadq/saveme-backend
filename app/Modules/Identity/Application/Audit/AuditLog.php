<?php

namespace App\Modules\Identity\Application\Audit;

use App\Modules\Identity\Domain\Ports\AuditStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The single writer of the audit log (security baseline, Who owns the audit
 * log). Other modules record through this service; nothing writes a second
 * store. Called inside the transaction of the act it records, so an act and
 * its entry commit together — exactly one entry per act.
 */
final class AuditLog
{
    public function __construct(
        private readonly AuditStore $store,
        private readonly Request $request,
    ) {}

    public function record(AuditRecord $record): void
    {
        $requestId = Context::get('request_id');

        $this->store->append([
            'id' => (string) Str::uuid7(),
            'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            'event' => $record->event->value,
            'actor_type' => $record->actorType->value,
            'actor_id' => $record->actorId,
            'subject_type' => $record->subjectType->value,
            'subject_id' => $record->subjectId,
            'request_id' => is_string($requestId) && Str::isUuid($requestId) ? $requestId : null,
            'origin' => self::coarsen($this->request->ip()),
            'detail' => $record->detail->toArray(),
        ]);
    }

    /**
     * The coarse origin the baseline asks for: a /24 for IPv4, a /48 for IPv6.
     */
    public static function coarsen(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);

            return "{$octets[0]}.{$octets[1]}.{$octets[2]}.0/24";
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $prefix = substr($packed, 0, 6).str_repeat("\0", 10);

        return inet_ntop($prefix).'/48';
    }
}
