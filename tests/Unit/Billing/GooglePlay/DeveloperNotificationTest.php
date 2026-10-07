<?php

use App\Modules\Billing\Domain\GooglePlay\DeveloperNotification;

/*
 * Parsing a real-time developer notification (spec 024, R-7): which purchase to read.
 */

function developerJson(array $members): string
{
    return json_encode(['version' => '1.0', 'packageName' => 'com.example.app', 'eventTimeMillis' => '1791277964000', ...$members]);
}

test('a subscription notification names the token, the type and the product', function () {
    $n = DeveloperNotification::parse(developerJson(['subscriptionNotification' => ['version' => '1.0', 'notificationType' => 2, 'purchaseToken' => 'tok-1', 'subscriptionId' => 'plus_monthly']]));

    expect($n->kind)->toBe(DeveloperNotification::SUBSCRIPTION)
        ->and($n->notificationType)->toBe(2)->and($n->purchaseToken)->toBe('tok-1')->and($n->productId)->toBe('plus_monthly')
        ->and($n->packageName)->toBe('com.example.app')->and($n->eventTimeMillis)->toBe(1791277964000);
});

test('a one-time product notification names the sku', function () {
    $n = DeveloperNotification::parse(developerJson(['oneTimeProductNotification' => ['version' => '1.0', 'notificationType' => 1, 'purchaseToken' => 'tok-2', 'sku' => 'plus_lifetime']]));

    expect($n->kind)->toBe(DeveloperNotification::ONE_TIME)->and($n->productId)->toBe('plus_lifetime')->and($n->purchaseToken)->toBe('tok-2');
});

test('a voided purchase notification carries the token and the refund type', function () {
    $n = DeveloperNotification::parse(developerJson(['voidedPurchaseNotification' => ['purchaseToken' => 'tok-3', 'orderId' => 'GPA.1', 'productType' => 1, 'refundType' => 1]]));

    expect($n->kind)->toBe(DeveloperNotification::VOIDED)->and($n->notificationType)->toBe(1)->and($n->purchaseToken)->toBe('tok-3');
});

test('a test notification has no purchase', function () {
    $n = DeveloperNotification::parse(developerJson(['testNotification' => ['version' => '1.0']]));

    expect($n->kind)->toBe(DeveloperNotification::TEST)->and($n->purchaseToken)->toBeNull();
});

test('anything else parses to null', function (string $json) {
    expect(DeveloperNotification::parse($json))->toBeNull();
})->with([
    'not json' => ['{nope'],
    'a list' => ['[1,2]'],
    'no package' => ['{"version":"1.0"}'],
    'no known member' => ['{"packageName":"a.b","eventTimeMillis":"1"}'],
    'a subscription without a token' => ['{"packageName":"a.b","subscriptionNotification":{"notificationType":2}}'],
    'a subscription without a type' => ['{"packageName":"a.b","subscriptionNotification":{"purchaseToken":"t"}}'],
    'a voided one without a token' => ['{"packageName":"a.b","voidedPurchaseNotification":{"orderId":"x"}}'],
]);
