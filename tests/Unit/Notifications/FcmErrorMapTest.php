<?php

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Infrastructure\Push\FcmErrorMap;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

function fcmAnswer(int $status, ?array $body = null, array $headers = []): Response
{
    return new Response(new PsrResponse($status, $headers, $body === null ? '' : json_encode($body)));
}

function fcmError(int $code, string $status, ?string $fcmCode = null, array $details = []): array
{
    return ['error' => [
        'code' => $code,
        'status' => $status,
        'message' => 'a message that must never be kept',
        'details' => $fcmCode === null ? $details : [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $fcmCode], ...$details],
    ]];
}

test('200 is accepted', function () {
    expect(FcmErrorMap::outcomeOf(fcmAnswer(200, ['name' => 'projects/p/messages/1']))->isAccepted())->toBeTrue();
});

test('404 UNREGISTERED is an invalid token', function () {
    $outcome = FcmErrorMap::outcomeOf(fcmAnswer(404, fcmError(404, 'NOT_FOUND', 'UNREGISTERED')));

    expect($outcome->isInvalidToken())->toBeTrue()->and($outcome->code)->toBe('UNREGISTERED');
});

test('400 INVALID_ARGUMENT about the token is an invalid token, about anything else a failure', function () {
    $aboutToken = fcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', [['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.token', 'description' => 'bad']]]]);
    $aboutData = fcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', [['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.data', 'description' => 'bad']]]]);

    expect(FcmErrorMap::outcomeOf(fcmAnswer(400, $aboutToken))->isInvalidToken())->toBeTrue()
        ->and(FcmErrorMap::outcomeOf(fcmAnswer(400, $aboutData))->kind)->toBe(GatewayOutcome::FAILED);
});

test('403 SENDER_ID_MISMATCH is an invalid token, other 403 a failure', function () {
    expect(FcmErrorMap::outcomeOf(fcmAnswer(403, fcmError(403, 'PERMISSION_DENIED', 'SENDER_ID_MISMATCH')))->isInvalidToken())->toBeTrue()
        ->and(FcmErrorMap::outcomeOf(fcmAnswer(403, fcmError(403, 'PERMISSION_DENIED')))->kind)->toBe(GatewayOutcome::FAILED);
});

test('429 is retried, after the Retry-After the provider names', function () {
    $outcome = FcmErrorMap::outcomeOf(fcmAnswer(429, fcmError(429, 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED'), ['Retry-After' => '120']));

    expect($outcome->isRetryable())->toBeTrue()->and($outcome->retryAfterSeconds)->toBe(120)->and($outcome->code)->toBe('QUOTA_EXCEEDED');
});

test('500 and 503 are retried', function () {
    expect(FcmErrorMap::outcomeOf(fcmAnswer(500, fcmError(500, 'INTERNAL', 'INTERNAL')))->isRetryable())->toBeTrue()
        ->and(FcmErrorMap::outcomeOf(fcmAnswer(503, fcmError(503, 'UNAVAILABLE', 'UNAVAILABLE'), ['Retry-After' => '30']))->retryAfterSeconds)->toBe(30)
        ->and(FcmErrorMap::outcomeOf(fcmAnswer(503))->isRetryable())->toBeTrue();
});

test('401 is retried once the token is refreshed', function () {
    expect(FcmErrorMap::outcomeOf(fcmAnswer(401, fcmError(401, 'UNAUTHENTICATED')))->isRetryable())->toBeTrue();
});

test('anything else fails with a code and never keeps a message body', function () {
    $outcome = FcmErrorMap::outcomeOf(fcmAnswer(418, ['error' => ['message' => 'secret detail']]));

    expect($outcome->kind)->toBe(GatewayOutcome::FAILED)->and($outcome->code)->toBe('HTTP_418');
});

test('a connection failure is retried', function () {
    $outcome = FcmErrorMap::connectionFailed();

    expect($outcome->isRetryable())->toBeTrue()->and($outcome->code)->toBe('CONNECTION_FAILED');
});
