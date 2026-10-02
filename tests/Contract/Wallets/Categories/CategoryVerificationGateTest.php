<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Str;

/*
 * Every category write is behind the verification gate; reads are not
 * (spec 011, FR-011; security baseline C1).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller('ada@example.test', verified: false);
    $this->category = ownedCategory($this->user->id);
});

test('an unverified person is 403 EMAIL_NOT_VERIFIED on every write', function (string $method, string $uri, array $body) {
    walletCall($method, $this->credential, str_replace('{id}', $this->category->id, $uri), $body)
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
})->with([
    'put' => ['PUT', '/categories/{id}', categoryBody()],
    'patch' => ['PATCH', '/categories/{id}', ['name' => 'X', 'version' => 1]],
    'archive' => ['POST', '/categories/{id}/archive', ['version' => 1]],
    'delete' => ['DELETE', '/categories/{id}', ['version' => 1]],
]);

test('the gate answers before the id is judged', function () {
    walletCall('PUT', $this->credential, '/categories/not-an-id', categoryBody())->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('an unverified person can create nothing', function () {
    $before = Category::query()->count();

    walletCall('PUT', $this->credential, '/categories/'.Str::uuid7(), categoryBody())->assertStatus(403);

    expect(Category::query()->count())->toBe($before);
});

test('reads work for an unverified person', function () {
    walletCall('GET', $this->credential, '/categories')->assertValidResponse(200);
    walletCall('GET', $this->credential, '/categories/'.$this->category->id)->assertValidResponse(200);
    walletCall('GET', $this->credential, '/platform-categories')->assertValidResponse(200);
});
