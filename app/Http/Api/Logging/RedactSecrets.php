<?php

namespace App\Http\Api\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Central redaction for every log channel (security baseline, What must never
 * be logged; P-15 rule 4). Any context or extra value whose key names a
 * password, token, secret, credential, authorization, cookie or a back-office
 * one-time code, recovery code, setup key or setup link, or an InstaPay receipt,
 * screenshot, redeem code or sealed claim address, is replaced,
 * at every depth, so no call site has to remember.
 */
final class RedactSecrets implements ProcessorInterface
{
    private const SENSITIVE_KEY = '/pass(word)?|token|secret|credential|authori[sz]ation|cookie|one_time_code|recovery_code|setup_key|otpauth|screenshot|receipt|redeem_code|address_sealed/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
