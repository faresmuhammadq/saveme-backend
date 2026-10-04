<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

/*
 * No password, credential, token, secret or authorization value reaches a log
 * line, at any level (security baseline, What must never be logged).
 */

test('sensitive keys are redacted at every depth and level', function () {
    $handler = new TestHandler;
    Log::channel('single')->getLogger()->pushHandler($handler);

    foreach (['debug', 'info', 'warning', 'error'] as $level) {
        Log::channel('single')->{$level}('event', [
            'password' => 'hunter2',
            'credential' => 'id|secret',
            'nested' => ['token' => 'abc', 'kept' => 'visible'],
            'authorization' => 'Bearer q',
        ]);
    }

    expect($handler->getRecords())->toHaveCount(4);

    foreach ($handler->getRecords() as $record) {
        expect($record->context['password'])->toBe('[REDACTED]')
            ->and($record->context['credential'])->toBe('[REDACTED]')
            ->and($record->context['nested']['token'])->toBe('[REDACTED]')
            ->and($record->context['nested']['kept'])->toBe('visible')
            ->and($record->context['authorization'])->toBe('[REDACTED]');
    }

    expect($handler->hasRecordThatContains('hunter2', Level::Debug))->toBeFalse();
});

test('back-office one-time codes, recovery codes and setup keys are redacted too', function () {
    $handler = new TestHandler;
    Log::channel('single')->getLogger()->pushHandler($handler);

    Log::channel('single')->info('event', [
        'one_time_code' => '123456',
        'recovery_code' => 'ABCDE-FGHJK',
        'setup_key' => 'ABCD EFGH',
        'otpauth' => 'otpauth://totp/x',
        'form' => ['one_time_code' => '654321'],
        'kept' => 'visible',
    ]);

    $record = $handler->getRecords()[0];

    expect($record->context['one_time_code'])->toBe('[REDACTED]')
        ->and($record->context['recovery_code'])->toBe('[REDACTED]')
        ->and($record->context['setup_key'])->toBe('[REDACTED]')
        ->and($record->context['otpauth'])->toBe('[REDACTED]')
        ->and($record->context['form']['one_time_code'])->toBe('[REDACTED]')
        ->and($record->context['kept'])->toBe('visible');
});
