<?php

use App\Modules\Notifications\Application\Delivery\DeliverNotification;
use App\Modules\Notifications\Application\Delivery\NotificationDelivery;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Messages\MessageType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;

const LEAK_TOKEN = 'LEAK-TEST-push-token-0123456789';

test('the token appears in no response, job, failed job, log line or event', function () {
    $user = person();
    $credentialSession = sessionIdFor($user);
    $logs = captureLogs();
    $events = [];
    Event::listen('*', function (string $name, array $payload) use (&$events): void {
        $events[] = json_encode([$name, array_map(fn (mixed $p): mixed => is_object($p) ? get_object_vars($p) : $p, $payload)], JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $register = $this->withHeaders(asCredential(credentialOf($credentialSession)))
        ->putJson("/api/v1/me/devices/{$credentialSession}", ['push_token' => LEAK_TOKEN]);
    $register->assertOk();

    $bodies = [$register->getContent()];

    // Deliver: accepted, then a transient failure, then an invalid token, with a queue that records payloads.
    Queue::fake();
    $first = app(NotificationService::class)->create(budgetDraft($user->id, 'k1'));
    $second = app(NotificationService::class)->create(budgetDraft($user->id, 'k2'));
    $third = app(NotificationService::class)->create(budgetDraft($user->id, 'k3'));
    fakePush()->script(LEAK_TOKEN, GatewayOutcome::accepted(), GatewayOutcome::retryLater(null, 'UNAVAILABLE'), GatewayOutcome::invalidToken('UNREGISTERED'));

    foreach ([$first, $second, $third] as $id) {
        app(NotificationDelivery::class)->deliver($id, 1, 5);
    }

    $pushed = [];
    Queue::assertPushed(DeliverNotification::class, function (DeliverNotification $job) use (&$pushed): bool {
        $pushed[] = serialize($job);

        return true;
    });

    $stored = [
        json_encode(DB::table('jobs')->get()),
        json_encode(DB::table('failed_jobs')->get()),
        json_encode(DB::table('push_notifications')->get()),
        json_encode(DB::table('push_deliveries')->get()),
        json_encode(DB::table('push_registrations')->get()),
    ];

    $everything = implode("\n", [...$bodies, ...$pushed, ...$stored, ...$logs->getArrayCopy(), ...$events]);

    // The registration row exists with the sealed value only.
    expect($everything)->not->toContain(LEAK_TOKEN)
        ->and(DB::table('push_registrations')->count())->toBe(0); // pruned by the invalid-token outcome
});

test('a message types data block never carries a token key', function () {
    foreach (MessageType::cases() as $type) {
        expect($type->allowedKeys())->not->toContain('push_token')->not->toContain('token');
    }
});

test('the central scrubber redacts the push token, the sealed value, the assertion, the access token and the private key at every depth and level', function () {
    $handler = new TestHandler;
    Log::channel('single')->getLogger()->pushHandler($handler);

    foreach (['debug', 'info', 'warning', 'error'] as $level) {
        Log::channel('single')->{$level}('event', [
            'push_token' => 'device-token',
            'token_sealed' => 'v1.n1.abc',
            'assertion' => 'eyJhbGciOiJSUzI1NiJ9.x.y',
            'access_token' => 'ya29.secret',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
            'nested' => ['push_token' => 'device-token', 'notification_id' => 'visible'],
        ]);
    }

    expect($handler->getRecords())->toHaveCount(4);

    foreach ($handler->getRecords() as $record) {
        foreach (['push_token', 'token_sealed', 'assertion', 'access_token', 'private_key'] as $key) {
            expect($record->context[$key])->toBe('[REDACTED]');
        }

        expect($record->context['nested']['push_token'])->toBe('[REDACTED]')
            ->and($record->context['nested']['notification_id'])->toBe('visible');
    }
});
