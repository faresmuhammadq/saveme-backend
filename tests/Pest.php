<?php

use App\Http\Api\Envelope;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Domain\Ports\RecoveryCodeHasher;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Domain\SecondFactor\RecoveryCode;
use App\Modules\Admin\Domain\SecondFactor\Totp;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Application\Dto\SavedBudget;
use App\Modules\Budgeting\Domain\BudgetProgress;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Http\Resources\BudgetResource;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Errors\Refusal;
use App\Modules\Sharing\Application\Mail\InvitationMail;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Application\Categories\CategoryUsage;
use App\Modules\Wallets\Application\Categories\CategoryUsageQuery;
use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Recurring\OccurrenceService;
use App\Modules\Wallets\Application\Recurring\RecurringPass;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Http\Resources\CategoryResource;
use App\Modules\Wallets\Http\Resources\OccurrenceResource;
use App\Modules\Wallets\Http\Resources\RecurringRuleResource;
use App\Modules\Wallets\Http\Resources\TransactionResource;
use App\Modules\Wallets\Http\Resources\WalletResource;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon as ClockInstant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
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

/*
|--------------------------------------------------------------------------
| Sync (spec 013)
|--------------------------------------------------------------------------
*/

/**
 * Reads a change feed page.
 *
 * @param  string  $feed  `user` or a wallet id
 */
function readFeed(string $credential, string $feed, ?string $cursor = null, ?int $limit = null): TestResponse
{
    $query = array_filter(['cursor' => $cursor, 'limit' => $limit], fn ($value) => $value !== null);
    $path = $feed === 'user' ? '/sync/user/changes' : '/sync/wallets/'.$feed.'/changes';

    return walletCall('GET', $credential, $path.($query === [] ? '' : '?'.http_build_query($query)));
}

/**
 * Reads a feed to its end and returns every row, with the last cursor.
 *
 * @return array{rows: list<array{type: string, record: array<string, mixed>}>, cursor: string}
 */
function readFeedFully(string $credential, string $feed, ?string $cursor = null, int $limit = 200): array
{
    $rows = [];

    do {
        $page = readFeed($credential, $feed, $cursor, $limit)->assertOk();
        $rows = [...$rows, ...$page->json('data')];
        $cursor = $page->json('meta.sync_cursor');
    } while ($page->json('meta.has_more'));

    return ['rows' => $rows, 'cursor' => $cursor];
}

/**
 * @param  list<array{type: string, record: array<string, mixed>}>  $rows
 * @return list<string> "type:id" of each row
 */
function feedKeys(array $rows): array
{
    return array_map(fn (array $row): string => $row['type'].':'.$row['record']['id'], $rows);
}

/**
 * Posts a sync batch.
 *
 * @param  list<array<string, mixed>>  $items
 */
function postBatch(string $credential, array $items): TestResponse
{
    return walletCall('POST', $credential, '/sync/batch', ['items' => $items]);
}

/**
 * A batch item.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function syncItem(string $type, string $op, string $id, array $data, ?string $walletId = null): array
{
    return ['type' => $type, 'op' => $op, 'id' => $id, ...($walletId === null ? [] : ['wallet_id' => $walletId]), 'data' => $data];
}

/*
|--------------------------------------------------------------------------
| Recurring transactions (spec 014)
|--------------------------------------------------------------------------
*/

pest()->afterEach(function () {
    ClockInstant::setTestNow();
})->in('Feature', 'Contract');

/**
 * Freezes the clock at a UTC instant, e.g. `2026-10-05 10:00:00`.
 */
function freezeClock(string $utc = '2026-10-05 10:00:00'): void
{
    ClockInstant::setTestNow(ClockInstant::parse($utc, 'UTC'));
}

/**
 * A full monthly rent rule write body (a platform category) with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function recurringRuleBody(array $changes = [], int $version = 0): array
{
    return [
        'preset' => 'custom',
        'status' => 'active',
        'kind' => 'expense',
        'amount_minor' => 450000,
        'amount_basis' => 'per_occurrence',
        'currency' => 'EGP',
        'category_id' => platformCategoryId(),
        'counter_wallet_id' => null,
        'description' => 'Rent',
        'memo' => null,
        'counterparty' => null,
        'auto_confirm' => false,
        'frequency' => 'monthly',
        'interval' => 1,
        'anchor_day' => 1,
        'start_date' => '2026-11-01',
        'end_date' => null,
        'occurrence_count' => null,
        'payout_round' => null,
        'payout_category_id' => null,
        'version' => $version,
        ...$changes,
    ];
}

/**
 * The values a rule save takes, as the service receives them.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function recurringRuleValues(array $body): array
{
    unset($body['version']);

    return $body;
}

/**
 * How a read renders the rule, as the service's `present` closure.
 *
 * @return Closure(RuleView): array<string, mixed>
 */
function presentRule(): Closure
{
    return RecurringRuleResource::toArray(...);
}

/**
 * How a read renders the occurrence, as the service's `present` closure.
 *
 * @return Closure(OccurrenceView): array<string, mixed>
 */
function presentOccurrence(): Closure
{
    return OccurrenceResource::toArray(...);
}

/**
 * Saves a rule through the service.
 *
 * @param  array<string, mixed>  $body  a write body, `version` included
 */
function saveRecurringRule(string $userId, string $walletId, string $ruleId, array $body): RuleView
{
    return app(RecurringRuleService::class)->save($userId, $walletId, $ruleId, recurringRuleValues($body), (int) ($body['version'] ?? 0), presentRule())->view;
}

/**
 * Creates a new rule (the rent rule unless the body says otherwise) through the service.
 *
 * @param  array<string, mixed>  $body
 */
function createRecurringRule(string $userId, string $walletId, array $body = []): RuleView
{
    return saveRecurringRule($userId, $walletId, (string) Str::uuid7(), $body === [] ? recurringRuleBody() : $body);
}

/**
 * Saves an occurrence through the service from the body's writable members.
 *
 * @param  array<string, mixed>  $body  a write body, `version` included
 */
function saveRecurringOccurrence(string $userId, string $walletId, string $occurrenceId, array $body): OccurrenceView
{
    $version = (int) $body['version'];
    unset($body['version']);

    return app(OccurrenceService::class)->save($userId, $walletId, $occurrenceId, $body, $version, presentOccurrence());
}

/**
 * The occurrence write body that leaves the stored occurrence as it is, with the changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function occurrenceBody(Occurrence|string $occurrence, array $changes = []): array
{
    $occurrence = $occurrence instanceof Occurrence ? $occurrence->fresh() : Occurrence::query()->findOrFail($occurrence);

    return [
        'status' => $occurrence->status,
        'date' => $occurrence->date,
        'amount_minor' => $occurrence->amount_minor,
        'category_id' => $occurrence->category_id,
        'description' => $occurrence->description,
        'memo' => $occurrence->memo,
        'counterparty' => $occurrence->counterparty,
        'transaction_id' => $occurrence->transaction_id,
        'version' => $occurrence->version,
        ...$changes,
    ];
}

/**
 * The occurrence write body that confirms it with a new transaction id.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function confirmBody(Occurrence|string $occurrence, array $changes = []): array
{
    return occurrenceBody($occurrence, ['status' => 'confirmed', 'transaction_id' => (string) Str::uuid7(), ...$changes]);
}

/**
 * A rule call with a credential, forgetting the guard afterwards.
 *
 * @param  array<string, mixed>  $body
 */
function recurringRuleCall(string $method, string $credential, string $walletId, string $ruleId, array $body = [], string $suffix = ''): TestResponse
{
    return walletCall($method, $credential, '/wallets/'.$walletId.'/recurring-rules/'.$ruleId.$suffix, $body);
}

/**
 * An occurrence call with a credential, forgetting the guard afterwards.
 *
 * @param  array<string, mixed>  $body
 */
function occurrenceCall(string $method, string $credential, string $walletId, string $occurrenceId, array $body = []): TestResponse
{
    return walletCall($method, $credential, '/wallets/'.$walletId.'/occurrences/'.$occurrenceId, $body);
}

/**
 * A rule's occurrences by date, payouts last on a tie, tombstones included only when asked.
 *
 * @return Collection<int, Occurrence>
 */
function occurrencesOf(string $ruleId, bool $withTombstones = false): Collection
{
    return Occurrence::query()
        ->where('recurring_rule_id', $ruleId)
        ->when(! $withTombstones, fn ($query) => $query->whereNull('deleted_at'))
        ->orderBy('scheduled_date')
        ->orderBy('is_payout')
        ->get();
}

/**
 * Runs the hourly job once.
 *
 * @return array{rules: int, materialised: int, confirmed: int, refused: int, failed: int}
 */
function runRecurringJob(): array
{
    return app(RecurringPass::class)->run();
}

/**
 * A verified owner with a wallet, and a verified editor and viewer in it.
 *
 * @return array{owner: User, wallet: Wallet, editor: User, viewer: User}
 */
function recurringWorld(): array
{
    $owner = person('owner@example.test');
    $editor = person('editor@example.test');
    $viewer = person('viewer@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $editor->id, 'editor');
    addMember($wallet->id, $viewer->id, 'viewer');

    return ['owner' => $owner, 'wallet' => $wallet, 'editor' => $editor, 'viewer' => $viewer];
}

/*
|--------------------------------------------------------------------------
| Budgets (spec 015)
|--------------------------------------------------------------------------
*/

/**
 * A full budget write body (a platform Groceries-like expense category) with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function budgetBody(array $changes = [], int $version = 0): array
{
    return [
        'category_id' => platformCategoryId(),
        'period_type' => 'monthly',
        'amount_minor' => 600000,
        'currency' => 'EGP',
        'version' => $version,
        ...$changes,
    ];
}

/**
 * The values a budget save takes, as the service receives them.
 *
 * @param  array<string, mixed>  $changes
 * @return array{category_id: ?string, period_type: string, amount_minor: int, currency: string}
 */
function budgetValues(array $changes = []): array
{
    $values = [...budgetBody($changes)];
    unset($values['version']);

    return $values;
}

/**
 * A person's budget through the factory (the owner's counter stamps it).
 *
 * @param  array<string, mixed>  $attributes
 */
function ownedBudget(string $userId, array $attributes = []): Budget
{
    return Budget::factory()->create(['user_id' => $userId, 'category_id' => platformCategoryId(), ...$attributes]);
}

/**
 * How a read renders the budget, as the service's `present` closure.
 *
 * @return Closure(BudgetRecord): array<string, mixed>
 */
function presentBudget(): Closure
{
    return BudgetResource::toArray(...);
}

/**
 * Saves a budget through the service.
 *
 * @param  array<string, mixed>  $body  a write body, `version` included
 */
function saveBudget(string $userId, string $budgetId, array $body): SavedBudget
{
    $values = $body;
    unset($values['version']);

    return app(BudgetService::class)->save($userId, $budgetId, $values, (int) ($body['version'] ?? 0), presentBudget());
}

/**
 * Creates a budget through the service and returns its record.
 *
 * @param  array<string, mixed>  $changes
 */
function createBudget(string $userId, array $changes = []): BudgetRecord
{
    return saveBudget($userId, (string) Str::uuid7(), budgetBody($changes))->budget;
}

/**
 * A budget call with a credential.
 *
 * @param  array<string, mixed>  $body
 */
function budgetCall(string $method, string $credential, string $uri, array $body = []): TestResponse
{
    return walletCall($method, $credential, $uri, $body);
}

/**
 * Records an expense in a wallet through the ledger service.
 *
 * @param  array<string, mixed>  $changes
 */
function spend(string $userId, string $walletId, int $amount, array $changes = []): TransactionView
{
    return recordTransaction($userId, $walletId, transactionBody(['amount_minor' => $amount, ...$changes]));
}

/**
 * Budget Progress of one budget through the query service, as the owner reads it.
 */
function budgetProgress(string $userId, string $budgetId, ?string $date = null): BudgetProgress
{
    return app(BudgetQueryService::class)->progressOf($userId, $budgetId, $date);
}

/**
 * The id of the n-th seeded platform category of the kind (0 is `platformCategoryId()`).
 */
function nthPlatformCategoryId(int $position, string $kind = 'expense'): string
{
    return (string) Category::query()->whereNull('user_id')->where('kind', $kind)->orderBy('position')->skip($position)->value('id');
}

/*
|--------------------------------------------------------------------------
| Reports (spec 019)
|--------------------------------------------------------------------------
*/

/**
 * A report read with a credential, the path and query under /api/v1.
 */
function reportCall(string $credential, string $uri): TestResponse
{
    return walletCall('GET', $credential, $uri);
}

/**
 * The summary scenario of spec 019: a cash wallet (opening 1,000.00) with
 * October income 10,000.00, expenses 3,000.00 and 500.00, a September expense
 * of 200.00 and a 2,000.00 transfer to a bank wallet. Needs the clock frozen in
 * October 2026.
 *
 * @return array{cash: Wallet, bank: Wallet}
 */
function reportScenario(string $userId): array
{
    $cash = ownedWallet($userId, ['opening_balance_minor' => 100000]);
    $bank = ownedWallet($userId, ['opening_balance_minor' => 0]);
    recordTransaction($userId, $cash->id, incomeBody(['amount_minor' => 1000000, 'date' => '2026-10-02']));
    spend($userId, $cash->id, 300000, ['date' => '2026-10-03']);
    spend($userId, $cash->id, 50000, ['date' => '2026-10-04']);
    spend($userId, $cash->id, 20000, ['date' => '2026-09-20']);
    recordTransaction($userId, $cash->id, transferBody($bank->id, ['amount_minor' => 200000, 'date' => '2026-10-05']));

    return ['cash' => $cash, 'bank' => $bank];
}

/**
 * The refusals every report read shares: not signed in (401), not acceptable
 * (406), an old client (426) and any method but GET and HEAD (405 with Allow).
 */
function assertReportSharedRefusals(string $credential, string $uri): void
{
    test()->withHeaders(jsonHeaders())->getJson('/api/v1'.$uri)->assertValidResponse(401);

    test()->call('GET', '/api/v1'.$uri, [], [], [], test()->transformHeadersToServerVars([...asCredential($credential), 'Accept' => 'text/html']))->assertStatus(406);

    test()->withHeaders([...asCredential($credential), 'X-Client-Version' => '0.0.1'])->getJson('/api/v1'.$uri)->assertStatus(426);

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $response = walletCall($method, $credential, $uri)->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');

        expect($response->headers->get('Allow'))->toContain('GET')->toContain('HEAD');
    }
}

/**
 * Money on the wire.
 *
 * @return array{amount_minor: int, currency: string}
 */
function wireMoney(int $minor, string $currency = 'EGP'): array
{
    return ['amount_minor' => $minor, 'currency' => $currency];
}

/*
|--------------------------------------------------------------------------
| Sharing helpers (spec 016)
|--------------------------------------------------------------------------
*/

/**
 * A verified wallet owner who holds Plus (sharing, 5 members) with one
 * wallet, signed in.
 *
 * @return array{0: User, 1: string, 2: Wallet}
 */
function plusOwner(string $email = 'owner@example.test'): array
{
    [$user, $credential] = walletCaller($email);
    Subscription::factory()->create(['user_id' => $user->id]);
    // The sign-in answered with the Free entitlements; a service called directly must not reuse that memo.
    app()->forgetScopedInstances();

    return [$user, $credential, ownedWallet($user->id)];
}

/**
 * PUT /wallets/{id}/invitations/{iid}; a fresh UUIDv7 unless given.
 *
 * @param  array<string, mixed>  $body
 */
function sendInvitation(string $credential, string $walletId, array $body = [], ?string $invitationId = null): TestResponse
{
    return walletCall('PUT', $credential, '/wallets/'.$walletId.'/invitations/'.($invitationId ?? (string) Str::uuid7()), [
        'role' => 'editor',
        'invitee_email' => 'invitee@example.test',
        ...$body,
    ]);
}

/**
 * The token inside an invitation link.
 */
function tokenOf(string $link): string
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

/**
 * The token of the latest invitation mail captured by Mail::fake().
 */
function mailedToken(): string
{
    $mails = Mail::sent(InvitationMail::class);

    return tokenOf($mails->last()->link);
}

/**
 * A pending invitation created through the service, so its token is known.
 *
 * @return array{id: string, token: string}
 */
function pendingInvitation(string $inviterId, string $walletId, ?string $email = 'invitee@example.test', string $role = 'editor'): array
{
    $id = (string) Str::uuid7();
    $token = InvitationSecret::generate();
    $address = $email === null ? null : app(InvitationAddresses::class)->normaliseAndLookup($email);

    Invitation::factory()
        ->withToken($token->value)
        ->create([
            'id' => $id,
            'wallet_id' => $walletId,
            'invited_by' => $inviterId,
            'role' => $role,
            'channel' => $email === null ? 'link' : 'email',
            'address_sealed' => $address === null ? null : app(InvitationAddresses::class)->seal($inviterId, $address['address']),
            'address_lookup' => $address['lookup'] ?? null,
        ]);

    return ['id' => $id, 'token' => $token->value];
}

/**
 * POST /invitations/{preview|accept|decline} with a token.
 */
function respondToInvitation(string $credential, string $action, string $token): TestResponse
{
    return walletCall('POST', $credential, '/invitations/'.$action, ['token' => $token]);
}

/**
 * The refusals every endpoint shares, as the path file documents them: 401
 * without a credential, 406 for a media type the API does not speak, 415 for a
 * body that is not JSON (only when the operation takes a body), 426 for a
 * client below the minimum version, and 400 MALFORMED_JSON for a body that is
 * not JSON text.
 *
 * @param  array<string, mixed>  $body
 */
function assertSharedRefusals(string $method, string $uri, array $body, string $credential): void
{
    $uri = '/api/v1'.$uri;
    $test = test();

    $test->withHeaders(jsonHeaders())->json($method, $uri, $body)->assertValidResponse(401);

    $test->call($method, $uri, [], [], [], $test->transformHeadersToServerVars([...asCredential($credential), 'Accept' => 'text/html']), '{}')
        ->assertStatus(406);

    if ($body !== []) {
        $test->call($method, $uri, [], [], [], $test->transformHeadersToServerVars([...asCredential($credential), 'Content-Type' => 'text/plain']), 'x=1')
            ->assertStatus(415);

        $test->call($method, $uri, [], [], [], $test->transformHeadersToServerVars([...asCredential($credential), 'Content-Type' => 'application/json']), '{not json')
            ->assertStatus(400)
            ->assertJsonPath('code', 'MALFORMED_JSON');
    }

    $test->withHeaders([...asCredential($credential), 'X-Client-Version' => '0.0.1'])->json($method, $uri, $body)->assertStatus(426);
}

/*
|--------------------------------------------------------------------------
| Operator sign-in (spec 018)
|--------------------------------------------------------------------------
|
| An operator is made through the factory, which seals through the real
| sealer. `makeOperator` returns the model with the plain email, password and
| second-factor secret the test needs to sign in; `signInOperator` puts a
| full sign-in into the session as the real flow would.
|
*/

/**
 * @param  'enrolled'|'awaiting_password_change'|'awaiting_enrolment'|'deactivated'  $state
 * @param  array<string, mixed>  $attributes
 * @return array{operator: OperatorAccount, email: string, password: string, secret: string|null}
 */
function makeOperator(string $state = 'enrolled', array $attributes = []): array
{
    static $sequence = 0;
    $sequence++;

    $email = $attributes['email'] ?? "operator{$sequence}@example.test";
    $password = $attributes['password'] ?? 'a long operator password';
    $name = $attributes['name'] ?? "Operator {$sequence}";
    $secret = $state === 'enrolled' ? ($attributes['secret'] ?? random_bytes(20)) : null;
    unset($attributes['email'], $attributes['password'], $attributes['name'], $attributes['secret']);

    $factory = OperatorAccount::factory()->withEmail($email)->withDisplayName($name)->withPassword($password);
    $factory = match ($state) {
        'enrolled' => $factory->enrolled($secret),
        'awaiting_password_change' => $factory->awaitingPasswordChange($password),
        'deactivated' => $factory->deactivated(),
        default => $factory->awaitingEnrolment(),
    };

    return ['operator' => $factory->create($attributes), 'email' => $email, 'password' => $password, 'secret' => $secret];
}

/**
 * Stores a set of recovery codes for the operator and returns them as they
 * would be typed (XXXXX-XXXXX).
 *
 * @return list<string>
 */
function giveRecoveryCodes(OperatorAccount $operator, int $count = 10): array
{
    $codes = array_map(fn (): RecoveryCode => RecoveryCode::generate(), range(1, $count));

    app(RecoveryCodeStore::class)->replaceSet(
        $operator->id,
        array_map(fn (RecoveryCode $code): string => app(RecoveryCodeHasher::class)->hash($code->value), $codes),
    );

    return array_map(fn (RecoveryCode $code): string => $code->display(), $codes);
}

/**
 * The code an authenticator would show, `$offsetSteps` 30-second steps from now.
 */
function currentCode(string $secret, int $offsetSteps = 0): string
{
    return Totp::codeAt($secret, Totp::stepAt(now()->getTimestamp()) + $offsetSteps);
}

/**
 * Signs the operator in as the real flow ends: guard, epoch, limits, proof.
 */
function signInOperator(OperatorAccount $operator): void
{
    app(OperatorSession::class)->start($operator->id, $operator->refresh()->credential_epoch);
}

/**
 * Leaves the session half signed in, at a stage.
 */
function signInOperatorPending(OperatorAccount $operator, SignInStage $stage): void
{
    app(PendingSignIn::class)->begin($operator->id, $stage, $operator->refresh()->credential_epoch);
}

/**
 * The snapshot of one component on a rendered page, as the browser would
 * send it back to the shared update endpoint.
 */
function livewireSnapshot(string $html, string $componentClass): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        $decoded = json_decode($snapshot, true);

        if (is_array($decoded) && str_ends_with((string) ($decoded['memo']['name'] ?? ''), Str::kebab(class_basename($componentClass)))) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot for {$componentClass} on the page.");
}

/**
 * A component update through the shared endpoint, as Livewire's client sends it.
 *
 * @param  list<array{method: string, params: list<mixed>, metadata?: array<string, mixed>}>  $calls
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $updates  property => value, as wire:model sends them
 */
function livewireUpdate(string $snapshot, array $calls = [], array $headers = [], array $updates = []): TestResponse
{
    return test()->postJson(
        route('default-livewire.update'),
        ['components' => [['snapshot' => $snapshot, 'updates' => (object) $updates, 'calls' => $calls]]],
        ['X-Livewire' => 'true', ...$headers],
    );
}

/*
|--------------------------------------------------------------------------
| InstaPay claims (spec 020)
|--------------------------------------------------------------------------
*/

/**
 * A real image of the given type; with $size, padded with trailing zero bytes
 * to exactly that many bytes (trailing data does not stop it parsing).
 */
function receiptBytes(string $type = 'png', ?int $size = null): string
{
    $image = imagecreatetruecolor(1, 1);
    ob_start();
    match ($type) {
        'jpeg' => imagejpeg($image),
        'webp' => imagewebp($image),
        default => imagepng($image),
    };
    $bytes = (string) ob_get_clean();

    return $size === null ? $bytes : str_pad($bytes, $size, "\0");
}

function receiptBase64(string $type = 'png', ?int $size = null): string
{
    return base64_encode(receiptBytes($type, $size));
}

/**
 * The media type for a receiptBytes() type name.
 */
function receiptMediaType(string $type = 'png'): string
{
    return "image/{$type}";
}

/**
 * The id of a seeded plan by its code (spec 009 catalogue).
 */
function planIdByCode(string $code = 'plus_yearly'): string
{
    return (string) Plan::query()->where('code', $code)->value('id');
}

/**
 * A valid claim body for the seeded Plus yearly plan (129,000 EGP minor units).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function claimBody(array $overrides = []): array
{
    return [
        'email' => 'mona@example.test',
        'plan_id' => planIdByCode('plus_yearly'),
        'amount_minor' => 129000,
        'currency' => 'EGP',
        'reference' => '4815 1623 4211',
        ...$overrides,
    ];
}

/**
 * The headers the website sends with a claim.
 *
 * @return array<string, string>
 */
function claimHeaders(?string $key = null, string $platform = 'web', array $extra = []): array
{
    return [
        ...clientHeaders($platform),
        'Accept' => 'application/json',
        'Idempotency-Key' => $key ?? 'claim-'.Str::lower(Str::random(24)),
        ...$extra,
    ];
}

/**
 * POST /billing/instapay/claims as the website.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function submitClaimOverHttp(array $body, array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/billing/instapay/claims', $body, [...claimHeaders(), ...$headers]);
}
