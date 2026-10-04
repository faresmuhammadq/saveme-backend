<?php

use App\Modules\Billing\Domain\Idempotency\CanonicalJson;

/*
 * The fingerprint behind Idempotency-Key (spec 020, R-2).
 */

test('object keys are sorted at every depth and lists keep their order', function () {
    expect(CanonicalJson::canonical('{"b":1,"a":{"d":[3,1,2],"c":true}}'))
        ->toBe('{"a":{"c":true,"d":[3,1,2]},"b":1}');
});

test('there is no whitespace, unicode is not escaped, numbers stay as given', function () {
    expect(CanonicalJson::canonical("{ \"name\" : \"بلس\" ,\n \"n\": 129000, \"f\": 1.5, \"url\": \"a/b\" }"))
        ->toBe('{"f":1.5,"n":129000,"name":"بلس","url":"a/b"}');
});

test('an empty object stays an object and an empty list stays a list', function () {
    expect(CanonicalJson::canonical('{"o":{},"l":[]}'))->toBe('{"l":[],"o":{}}');
});

test('bodies differing only in key order or spacing share a fingerprint', function () {
    $a = CanonicalJson::fingerprint('POST', '/api/v1/billing/redeem', '{"code":"A","x":{"p":1,"q":2}}');
    $b = CanonicalJson::fingerprint('POST', '/api/v1/billing/redeem', "{ \"x\": {\"q\": 2, \"p\": 1},\n\"code\": \"A\" }");

    expect($a)->toBe($b)->toMatch('/^[0-9a-f]{64}$/');
});

test('any change of value, method or path changes the fingerprint', function () {
    $base = CanonicalJson::fingerprint('POST', '/api/v1/billing/redeem', '{"code":"A"}');

    expect(CanonicalJson::fingerprint('POST', '/api/v1/billing/redeem', '{"code":"B"}'))->not->toBe($base)
        ->and(CanonicalJson::fingerprint('PUT', '/api/v1/billing/redeem', '{"code":"A"}'))->not->toBe($base)
        ->and(CanonicalJson::fingerprint('POST', '/api/v1/billing/other', '{"code":"A"}'))->not->toBe($base)
        ->and(CanonicalJson::fingerprint('post', '/api/v1/billing/redeem', '{"code":"A"}'))->toBe($base);
});

test('a body that is not JSON is hashed as sent', function () {
    expect(CanonicalJson::canonical('not json'))->toBe('not json');
});
