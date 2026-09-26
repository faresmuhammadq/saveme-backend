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
