<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use Illuminate\Http\Client\Response;

/**
 * FCM HTTP v1 answers mapped to the gateway's closed outcome set (spec 023,
 * research R-9, A-11). Only a provider error code (letters and underscores)
 * is kept, never a body.
 */
final class FcmErrorMap
{
    public static function outcomeOf(Response $response): GatewayOutcome
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return GatewayOutcome::accepted();
        }

        $code = self::errorCode($response);
        $retryAfter = self::retryAfter($response);

        return match (true) {
            $status === 404 && $code === 'UNREGISTERED' => GatewayOutcome::invalidToken($code),
            $status === 400 && $code === 'INVALID_ARGUMENT' => self::namesTheToken($response)
                ? GatewayOutcome::invalidToken($code)
                : GatewayOutcome::failed($code),
            $status === 403 && $code === 'SENDER_ID_MISMATCH' => GatewayOutcome::invalidToken($code),
            $status === 429, $code === 'QUOTA_EXCEEDED' => GatewayOutcome::retryLater($retryAfter, $code ?? 'QUOTA_EXCEEDED'),
            $status === 500, $status === 503, $code === 'UNAVAILABLE', $code === 'INTERNAL' => GatewayOutcome::retryLater($retryAfter, $code ?? "HTTP_{$status}"),
            $status === 401 => GatewayOutcome::retryLater($retryAfter, $code ?? 'UNAUTHENTICATED'),
            default => GatewayOutcome::failed($code ?? "HTTP_{$status}"),
        };
    }

    /**
     * An outcome for a transport failure (no answer at all).
     */
    public static function connectionFailed(): GatewayOutcome
    {
        return GatewayOutcome::retryLater(null, 'CONNECTION_FAILED');
    }

    private static function errorCode(Response $response): ?string
    {
        $details = $response->json('error.details');

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && is_string($detail['errorCode'] ?? null)) {
                    return self::sanitise($detail['errorCode']);
                }
            }
        }

        $status = $response->json('error.status');

        return is_string($status) ? self::sanitise($status) : null;
    }

    private static function sanitise(string $code): ?string
    {
        return preg_match('/^[A-Z][A-Z_]{0,62}$/', $code) === 1 ? $code : null;
    }

    private static function namesTheToken(Response $response): bool
    {
        $details = $response->json('error.details');

        if (! is_array($details)) {
            return false;
        }

        foreach ($details as $detail) {
            $violations = is_array($detail) ? ($detail['fieldViolations'] ?? null) : null;

            if (! is_array($violations)) {
                continue;
            }

            foreach ($violations as $violation) {
                if (is_array($violation) && ($violation['field'] ?? null) === 'message.token') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }
}
