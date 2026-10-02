<?php

use App\Http\Api\Envelope;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Errors\Refusal;
use App\Modules\Wallets\Application\Categories\CategoryUsage;
use App\Modules\Wallets\Application\Categories\CategoryUsageQuery;
use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Http\Resources\CategoryResource;
use App\Modules\Wallets\Http\Resources\TransactionResource;
use App\Modules\Wallets\Http\Resources\WalletResource;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Contract\Api\Support\ProbeRequest;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suites
|--------------------------------------------------------------------------
|
| Four suites, one per layer plus the architecture gates (spec 005):
|
|   Arch      no application, no database — boundaries, migrations, skeleton
|   Unit      domain rules, no framework, no database
|   Feature   application services against PostgreSQL, refreshed per test
|   Contract  HTTP endpoints against PostgreSQL, validated against the
|             OpenAPI definition in docs/openapi through Spectator
|
| Unit and Arch deliberately do not extend the application TestCase: a domain
| rule that needs the framework to be tested is in the wrong layer.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contract');

/*
|--------------------------------------------------------------------------
| Contract tests
|--------------------------------------------------------------------------
|
| Every Contract test validates against the published definition by default.
| An endpoint test then asserts conformance in one line:
|
|     $this->withHeaders(clientHeaders())
|         ->getJson('/api/v1/wallets/'.$id)
|         ->assertValidRequest()
|         ->assertValidResponse(200);
|
| Remember that an empty PHP array serialises to JSON [] — an empty envelope
| meta must be emitted as (object) [] to satisfy the contract.
|
*/

pest()->beforeEach(function () {
    Spectator\Spectator::using('openapi.yaml');
})->in('Contract');

/**
 * The headers every request to the API must carry (docs/api-conventions.md).
 *
 * @return array<string, string>
 */
function clientHeaders(string $platform = 'android', string $version = '1.0.0'): array
{
    return ['X-Client-Platform' => $platform, 'X-Client-Version' => $version];
}

/*
|--------------------------------------------------------------------------
| Back office (spec 006)
|--------------------------------------------------------------------------
|
| Primitives are anonymous Blade components; tests render them in a given
| locale and assert on the HTML. Vite is replaced so no build is needed.
|
*/

pest()->beforeEach(function () {
    $this->withoutVite();
})->in('Feature/Admin');

/**
 * Renders a Blade snippet using the back-office primitives in the given locale.
 *
 * @param  array<string, mixed>  $data
 */
function renderAdmin(string $blade, array $data = [], string $locale = 'en'): string
{
    app()->setLocale($locale);
    Carbon\Carbon::setLocale($locale);

    return (string) test()->blade($blade, $data);
}

/**
 * Renders a snippet inside the back-office HTML document, so lang and dir apply.
 *
 * @param  array<string, mixed>  $data
 */
function renderAdminDocument(string $blade, array $data = [], string $locale = 'en'): string
{
    return renderAdmin('<x-admin::layouts.document title="Test">'.$blade.'</x-admin::layouts.document>', $data, $locale);
}

expect()->extend('toUseWesternDigitsOnly', function () {
    expect(preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', (string) $this->value))
        ->toBe(0, 'P-14: output contains Eastern Arabic-Indic digits');

    return $this;
});

/**
 * Re-registers the back-office routes as they would be in the given
 * environment, replacing whatever the test application booted with.
 */
function registerAdminRoutesAs(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);

    $router = app('router');
    $kept = new RouteCollection;

    foreach ($router->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin')) {
            $kept->add($route);
        }
    }

    $router->setRoutes($kept);
    require app_path('Modules/Admin/Http/routes.php');
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

/**
 * @return list<string> URIs of registered back-office routes
 */
function adminRouteUris(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->map(fn (Route $route): string => $route->uri())
        ->filter(fn (string $uri): bool => $uri === 'admin' || str_starts_with($uri, 'admin/'))
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| API runtime (spec 007)
|--------------------------------------------------------------------------
|
| Fixture routes that run through the real `api` middleware group but are not
| part of the published contract, so runtime tests assert on raw JSON and
| headers rather than through Spectator.
|
*/

function registerApiProbes(): void
{
    Illuminate\Support\Facades\Route::middleware('api')->prefix('api/v1')->group(function (): void {
        Illuminate\Support\Facades\Route::get('_probe', fn () => Envelope::ok(['ok' => true]));
        Illuminate\Support\Facades\Route::post('_probe', fn (ProbeRequest $request) => Envelope::ok($request->validated()));
        Illuminate\Support\Facades\Route::get('_boom', fn () => throw new RuntimeException('secret stack detail'));
        Illuminate\Support\Facades\Route::get('_throttled', fn () => Envelope::ok(['ok' => true]))->middleware('throttle:2,1');
    });
}

/**
 * The headers a JSON client sends, plus the client headers.
 *
 * @return array<string, string>
 */
function jsonHeaders(array $extra = []): array
{
    // Each call is judged afresh, as in production: no user cached on the guard.
    if (function_exists('app') && app()->bound('auth')) {
        app('auth')->forgetGuards();
    }

    return [...clientHeaders(), 'Accept' => 'application/json', ...$extra];
}

expect()->extend('toBeUuidV7', function () {
    expect($this->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Identity (spec 007)
|--------------------------------------------------------------------------
*/

/**
 * A sign-in device description with a fresh session id.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function deviceBody(array $overrides = []): array
{
    return [
        'session_id' => (string) Str::uuid7(),
        'device_id' => 'install-'.Str::lower(Str::random(10)),
        'name' => 'Pixel 8',
        'platform' => 'android',
        'app_version' => '1.0.0',
        ...$overrides,
    ];
}

/**
 * A registered, verified person with a known address and password.
 */
function person(string $email = 'ada@example.test', string $password = 'correct horse', bool $verified = true): User
{
    $factory = User::factory()->withEmail($email)->withPassword($password);
    $user = ($verified ? $factory->verified() : $factory->unverified())->create();
    Profile::factory()->create(['user_id' => $user->id]);

    return $user;
}

/**
 * Signs in through the API and returns the response.
 *
 * @param  array<string, string>|null  $device
 */
function signInAs(string $email = 'ada@example.test', string $password = 'correct horse', ?array $device = null): TestResponse
{
    $response = test()->withHeaders(jsonHeaders())->postJson('/api/v1/auth/login', [
        'email' => $email, 'password' => $password, 'device' => $device ?? deviceBody(),
    ]);
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return $response;
}

/**
 * Headers for an authenticated call with a credential; forgets the guard so
 * each call is judged afresh, as in production.
 *
 * @return array<string, string>
 */
function asCredential(string $credential): array
{
    app('auth')->forgetGuards();

    return jsonHeaders(['Authorization' => 'Bearer '.$credential]);
}

/**
 * Issues a live link of the given kind for a user, returning its secret.
 */
function linkFor(string $userId, LinkKind $kind): string
{
    return app(LinkRepository::class)->issue($userId, $kind);
}

/**
 * Spectator 3 cannot validate a request carrying the shared deepObject
 * `filter[...]` parameter: its caster reads `properties` on a schema that has
 * only `additionalProperties`, and the warning becomes an exception. Tests of
 * filtered requests switch it off and assert on status and code directly.
 * Remove when Spectator handles additionalProperties-only objects.
 */
function withoutSpectatorRequestValidation(): void
{
    Spectator\Spectator::reset();
}

/**
 * Calls POST /auth/change-password with a credential.
 *
 * @param  array<string, string>  $body
 */
function changePassword(string $credential, array $body): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->postJson('/api/v1/auth/change-password', $body);
    test()->flushHeaders();

    return $response;
}

/**
 * A full profile save body with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function profileBody(array $changes = [], int $version = 1): array
{
    return [
        'display_name' => null,
        'locale' => 'en',
        'timezone' => 'Africa/Cairo',
        'default_currency' => 'EGP',
        'budget_period_start_day' => 1,
        'version' => $version,
        ...$changes,
    ];
}

/**
 * Calls PUT /me/profile with a credential.
 *
 * @param  array<string, mixed>  $body
 */
function saveProfile(string $credential, array $body): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->putJson('/api/v1/me/profile', $body);
    test()->flushHeaders();

    return $response;
}

/*
|--------------------------------------------------------------------------
| Wallets (spec 010)
|--------------------------------------------------------------------------
*/

/**
 * A full wallet write body with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function walletBody(array $changes = [], int $version = 0): array
{
    return [
        'type' => 'cash',
        'name' => 'Cash',
        'currency' => 'EGP',
        'opening_balance_minor' => 50000,
        'icon' => 'wallet',
        'color' => '#2E7D32',
        'version' => $version,
        ...$changes,
    ];
}

/**
 * The values a wallet save takes, as the service receives them.
 *
 * @param  array<string, mixed>  $changes
 * @return array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string}
 */
function walletValues(array $changes = []): array
{
    $values = [...walletBody($changes)];
    unset($values['version']);

    return $values;
}

/**
 * A wallet call with a credential, forgetting the guard afterwards so the
 * next call is judged afresh.
 *
 * @param  array<string, mixed>  $body
 */
function walletCall(string $method, string $credential, string $uri, array $body = []): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->json($method, '/api/v1'.$uri, $body);
    test()->flushHeaders();

    return $response;
}

/**
 * A verified person with a credential, ready to call the wallet API.
 *
 * @return array{0: User, 1: string}
 */
function walletCaller(string $email = 'ada@example.test', bool $verified = true): array
{
    $user = person($email, 'correct horse', $verified);

    return [$user, signInAs($email)->json('data.credential')];
}

/**
 * A wallet owned by the person, through the factory (membership included).
 *
 * @param  array<string, mixed>  $attributes
 */
function ownedWallet(string $userId, array $attributes = []): Wallet
{
    return Wallet::factory()->ownedBy($userId)->create($attributes);
}

/**
 * Adds a member to a wallet, giving the membership the next change_seq and
 * the wallet the last, as a real write leaves them.
 */
function addMember(string $walletId, string $userId, string $role): WalletMember
{
    $seq = app(ScopeSequence::class)->nextFor($walletId);
    $member = WalletMember::factory()->create(['wallet_id' => $walletId, 'user_id' => $userId, 'role' => $role, 'change_seq' => $seq]);
    app(ScopeSequence::class)->nextFor($walletId);

    return $member;
}

/**
 * How a read renders the wallet, as the service's `present` closure.
 *
 * @return Closure(WalletView): array<string, mixed>
 */
function presentWallet(): Closure
{
    return WalletResource::toArray(...);
}

/**
 * Binds a posting stand-in answering the given sum and `hasAny`.
 */
function fakePostings(int $sum = 0, bool $hasAny = false): void
{
    app()->bind(PostingTotals::class, fn () => new class($sum, $hasAny) implements PostingTotals
    {
        public function __construct(private int $sum, private bool $hasAny) {}

        public function sumFor(string $walletId): int
        {
            return $this->sum;
        }

        public function hasAny(string $walletId): bool
        {
            return $this->hasAny;
        }

        public function lockedAmong(array $walletIds): array
        {
            return $this->hasAny ? $walletIds : [];
        }
    });
}

/*
|--------------------------------------------------------------------------
| Categories (spec 011)
|--------------------------------------------------------------------------
*/

/**
 * A full category write body with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function categoryBody(array $changes = [], int $version = 0): array
{
    return [
        'kind' => 'expense',
        'name' => 'Coffee',
        'icon' => 'cup',
        'color' => '#6D4C41',
        'version' => $version,
        ...$changes,
    ];
}

/**
 * The values a category save takes, as the service receives them.
 *
 * @param  array<string, mixed>  $changes
 * @return array{kind: string, name: string, icon: ?string, color: ?string}
 */
function categoryValues(array $changes = []): array
{
    $values = [...categoryBody($changes)];
    unset($values['version']);

    return $values;
}

/**
 * A person's category through the factory.
 *
 * @param  array<string, mixed>  $attributes
 */
function ownedCategory(string $userId, array $attributes = []): Category
{
    return Category::factory()->create(['user_id' => $userId, ...$attributes]);
}

/**
 * How a read renders the category, as the service's `present` closure.
 *
 * @return Closure(CategoryView): array<string, mixed>
 */
function presentCategory(): Closure
{
    return CategoryResource::toArray(...);
}

/**
 * Registers a usage participant that replaces the ledger's and calls the given categories used.
 *
 * @param  list<string>  $usedIds
 */
function fakeCategoryUsage(array $usedIds): void
{
    $participant = new class($usedIds) implements CategoryUsage
    {
        /** @param list<string> $used */
        public function __construct(private array $used) {}

        public function isUsed(string $categoryId): bool
        {
            return in_array($categoryId, $this->used, true);
        }

        public function usedAmong(array $categoryIds): array
        {
            return array_values(array_intersect($categoryIds, $this->used));
        }
    };

    app()->bind(CategoryUsageQuery::class, fn () => new CategoryUsageQuery([$participant]));
}

/*
|--------------------------------------------------------------------------
| Transactions (spec 012)
|--------------------------------------------------------------------------
*/

/**
 * The id of a seeded platform category of the kind.
 */
function platformCategoryId(string $kind = 'expense'): string
{
    return (string) Category::query()->whereNull('user_id')->where('kind', $kind)->orderBy('position')->value('id');
}

/**
 * A full expense write body (a platform category) with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function transactionBody(array $changes = [], int $version = 0): array
{
    return [
        'kind' => 'expense',
        'date' => '2026-10-01',
        'amount_minor' => 2500,
        'currency' => 'EGP',
        'category_id' => platformCategoryId(),
        'counter_wallet_id' => null,
        'description' => 'Lunch',
        'memo' => null,
        'counterparty' => null,
        'cleared' => false,
        'version' => $version,
        ...$changes,
    ];
}

/**
 * A full income write body (a platform category) with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function incomeBody(array $changes = [], int $version = 0): array
{
    return transactionBody(['kind' => 'income', 'category_id' => platformCategoryId('income'), 'description' => 'Salary', ...$changes], $version);
}

/**
 * A full transfer write body to the destination wallet.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function transferBody(string $counterWalletId, array $changes = [], int $version = 0): array
{
    return transactionBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $counterWalletId, 'description' => 'Move', ...$changes], $version);
}

/**
 * The values a transaction save takes, as the service receives them.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function transactionValues(array $body): array
{
    unset($body['version']);

    return $body;
}

/**
 * How a read renders the transaction, as the service's `present` closure.
 *
 * @return Closure(TransactionView): array<string, mixed>
 */
function presentTransaction(): Closure
{
    return TransactionResource::toArray(...);
}

/**
 * Saves a transaction through the service.
 *
 * @param  array<string, mixed>  $body  a write body, `version` included
 */
function saveTransaction(string $userId, string $walletId, string $transactionId, array $body): TransactionView
{
    return app(TransactionService::class)->save($userId, $walletId, $transactionId, transactionValues($body), (int) ($body['version'] ?? 0), presentTransaction())->view;
}

/**
 * Records a new expense (or what the body says) through the service.
 *
 * @param  array<string, mixed>  $body
 */
function recordTransaction(string $userId, string $walletId, array $body = []): TransactionView
{
    return saveTransaction($userId, $walletId, (string) Str::uuid7(), $body === [] ? transactionBody() : $body);
}

/**
 * A transaction call with a credential, forgetting the guard afterwards.
 *
 * @param  array<string, mixed>  $body
 */
function transactionCall(string $method, string $credential, string $walletId, string $transactionId, array $body = []): TestResponse
{
    return walletCall($method, $credential, '/wallets/'.$walletId.'/transactions/'.$transactionId, $body);
}

/**
 * A wallet's cached balance, read from the row.
 */
function walletBalance(string $walletId): int
{
    return (int) Wallet::query()->whereKey($walletId)->value('balance_minor');
}

/**
 * Every change_seq in a wallet's scope that a transaction write stamps: the
 * wallet's own, the memberships', the references' and the transactions'
 * (a transfer's counter stamp in the destination's scope).
 *
 * @return list<int>
 */
function transactionScopeSequences(string $walletId): array
{
    return [
        (int) Wallet::query()->whereKey($walletId)->value('change_seq'),
        ...WalletMember::query()->where('wallet_id', $walletId)->pluck('change_seq')->map(fn ($seq): int => (int) $seq)->all(),
        ...CategoryReference::query()->where('wallet_id', $walletId)->pluck('change_seq')->map(fn ($seq): int => (int) $seq)->all(),
        ...Transaction::query()->where('wallet_id', $walletId)->pluck('change_seq')->map(fn ($seq): int => (int) $seq)->all(),
        ...Transaction::query()->where('counter_wallet_id', $walletId)->pluck('counter_change_seq')->map(fn ($seq): int => (int) $seq)->all(),
    ];
}

/**
 * The wallet's change_seq is the highest in its scope and no two records
 * share a value (spec 012, R-5).
 */
function expectTransactionScopeIntact(string $walletId): void
{
    $sequences = transactionScopeSequences($walletId);
    $wallet = array_shift($sequences);

    expect(array_unique($sequences))->toHaveCount(count($sequences))
        ->and($wallet)->toBeGreaterThan(max($sequences));
}

/**
 * Every live transaction has exactly two postings summing to zero with one
 * target each, a tombstone has none, and the wallet's cache is opening +
 * Σ postings (spec 012, P-04, P-05).
 */
function expectLedgerIntact(): void
{
    foreach (Transaction::query()->get() as $transaction) {
        $postings = Posting::query()->where('transaction_id', $transaction->id)->get();

        if ($transaction->deleted_at !== null) {
            expect($postings)->toHaveCount(0);

            continue;
        }

        expect($postings)->toHaveCount(2)
            ->and((int) $postings->sum('amount_minor'))->toBe(0)
            ->and($postings->every(fn (Posting $posting): bool => ($posting->wallet_id === null) !== ($posting->category_id === null)))->toBeTrue();
    }

    foreach (Wallet::query()->whereNull('deleted_at')->get() as $wallet) {
        expect($wallet->balance_minor)->toBe($wallet->opening_balance_minor + (int) Posting::query()->where('wallet_id', $wallet->id)->sum('amount_minor'));
    }
}

/**
 * The refusal an action throws, or null when it succeeds.
 */
function refusalOf(Closure $action): ?Refusal
{
    try {
        $action();
    } catch (Refusal $refusal) {
        return $refusal;
    }

    return null;
}

/**
 * The field errors of a refusal as `pointer CODE` strings.
 *
 * @return list<string>
 */
function fieldErrorsOf(?Refusal $refusal): array
{
    return array_map(fn ($error): string => $error->pointer.' '.$error->code->value, $refusal->fieldErrors ?? []);
}
