<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Recurring\RecurringQueryService;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Wallet;

/*
 * Rows R-01 and R-02 of spec 010, and T-02 in a transfer rule's destination
 * (spec 014, US1 scenario 7, FR-029). The matrix is asked through
 * WalletAuthorizer, never by comparing a role.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->wallet = $this->world['wallet'];
    $this->stranger = person('stranger@example.test');
});

it('lets an owner and an editor write', function (string $who) {
    $view = createRecurringRule($this->world[$who]->id, $this->wallet->id);

    expect($view->rule->createdBy)->toBe($this->world[$who]->id);
})->with(['owner', 'editor']);

it('refuses a viewer with forbidden and a non-member with not-found, writing nothing', function () {
    $viewer = refusalOf(fn () => createRecurringRule($this->world['viewer']->id, $this->wallet->id));
    $stranger = refusalOf(fn () => createRecurringRule($this->stranger->id, $this->wallet->id));

    expect($viewer?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and($stranger?->errorCode)->toBe(ErrorCode::NotFound);
    expect(RecurringRule::query()->count())->toBe(0);
});

it('refuses every write of a viewer and a non-member: edit, delete, materialise, occurrence', function () {
    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    $service = app(RecurringRuleService::class);

    foreach (['viewer' => ErrorCode::Forbidden, 'stranger' => ErrorCode::NotFound] as $who => $code) {
        $userId = $who === 'viewer' ? $this->world['viewer']->id : $this->stranger->id;

        expect(refusalOf(fn () => saveRecurringRule($userId, $this->wallet->id, $rule->id, recurringRuleBody(['amount_minor' => 1], 1)))?->errorCode)->toBe($code)
            ->and(refusalOf(fn () => $service->delete($userId, $this->wallet->id, $rule->id, 1, presentRule()))?->errorCode)->toBe($code)
            ->and(refusalOf(fn () => $service->materialise($userId, $this->wallet->id, $rule->id, presentRule()))?->errorCode)->toBe($code)
            ->and(refusalOf(fn () => saveRecurringOccurrence($userId, $this->wallet->id, $occurrence->id, occurrenceBody($occurrence, ['status' => 'skipped'])))?->errorCode)->toBe($code);
    }
});

it('opens reads to every member and closes them to a non-member', function () {
    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    $reads = app(RecurringQueryService::class);

    foreach (['viewer', 'editor', 'owner'] as $who) {
        $id = $this->world[$who]->id;

        expect($reads->findRule($id, $this->wallet->id, $rule->id)->rule->id)->toBe($rule->id)
            ->and($reads->findOccurrence($id, $this->wallet->id, $occurrence->id)->occurrence->id)->toBe($occurrence->id)
            ->and($reads->listRules($id, $this->wallet->id, [], 'created_at', false, null, 50)['items'])->toHaveCount(1)
            ->and($reads->listOccurrences($id, $this->wallet->id, [], 'date', false, null, 50)['items'])->toHaveCount(1);
    }

    expect(refusalOf(fn () => $reads->findRule($this->stranger->id, $this->wallet->id, $rule->id))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(refusalOf(fn () => $reads->listOccurrences($this->stranger->id, $this->wallet->id, [], 'date', false, null, 50))?->errorCode)->toBe(ErrorCode::NotFound);
});

it('answers not-found for a rule or occurrence read under another wallet\'s path', function () {
    $other = ownedWallet($this->world['owner']->id);
    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    $reads = app(RecurringQueryService::class);

    expect(refusalOf(fn () => $reads->findRule($this->world['owner']->id, $other->id, $rule->id))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(refusalOf(fn () => $reads->findOccurrence($this->world['owner']->id, $other->id, $occurrence->id))?->errorCode)->toBe(ErrorCode::NotFound);
});

it('needs T-02 in a transfer rule\'s destination: a viewer there is forbidden, a non-member is a field error', function () {
    $destination = ownedWallet($this->world['owner']->id);
    addMember($destination->id, $this->world['editor']->id, 'viewer');
    $stranger = ownedWallet($this->stranger->id);

    $viewerThere = refusalOf(fn () => createRecurringRule($this->world['editor']->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id])));
    $notAMember = refusalOf(fn () => createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $stranger->id])));
    $ok = createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]));

    expect($viewerThere?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(fieldErrorsOf($notAMember))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE'])
        ->and($ok->rule->counterWalletId)->toBe($destination->id);
});

it('keeps asking for T-02 in the destination on every later write and on a confirmation', function () {
    $destination = ownedWallet($this->world['owner']->id);
    $editorThere = addMember($destination->id, $this->world['editor']->id, 'editor');
    $rule = createRecurringRule($this->world['editor']->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();

    $editorThere->forceFill(['role' => 'viewer'])->save();

    expect(refusalOf(fn () => saveRecurringRule($this->world['editor']->id, $this->wallet->id, $rule->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id, 'amount_minor' => 1], 1)))?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(refusalOf(fn () => app(RecurringRuleService::class)->delete($this->world['editor']->id, $this->wallet->id, $rule->id, 1, presentRule()))?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(refusalOf(fn () => saveRecurringOccurrence($this->world['editor']->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)))?->errorCode)->toBe(ErrorCode::Forbidden);
});

it('reads a transfer rule under its source wallet only', function () {
    $destination = ownedWallet($this->world['owner']->id);
    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $reads = app(RecurringQueryService::class);

    expect(refusalOf(fn () => $reads->findRule($this->world['owner']->id, $destination->id, $rule->id))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and($reads->listRules($this->world['owner']->id, $destination->id, [], 'created_at', false, null, 50)['items'])->toHaveCount(0);
});

it('lets a rule be paused and ended after the wallet\'s currency changed, and refuses its confirmations', function () {
    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    Wallet::query()->whereKey($this->wallet->id)->update(['currency' => 'USD']);

    $confirm = refusalOf(fn () => saveRecurringOccurrence($this->world['owner']->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)));
    $paused = saveRecurringRule($this->world['owner']->id, $this->wallet->id, $rule->id, recurringRuleBody(['status' => 'paused'], 1));
    $ended = saveRecurringRule($this->world['owner']->id, $this->wallet->id, $rule->id, recurringRuleBody(['status' => 'ended'], 2));

    expect(fieldErrorsOf($confirm))->toBe(['/status CURRENCY_MISMATCH'])
        ->and($paused->rule->status->value)->toBe('paused')
        ->and($ended->rule->status->value)->toBe('ended');
});

it('asks the authorizer for its rows and never compares a role', function () {
    foreach (glob(app_path('Modules/Wallets/Application/Recurring/*.php')) ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain('Role::')->and(file_get_contents($file))->not->toContain('->role');
    }
});

it('does not let one wallet\'s member write a rule under an id another wallet holds', function () {
    $other = ownedWallet($this->stranger->id);
    $rule = createRecurringRule($this->stranger->id, $other->id)->rule;

    $refusal = refusalOf(fn () => saveRecurringRule($this->world['owner']->id, $this->wallet->id, $rule->id, recurringRuleBody()));

    expect($refusal?->errorCode)->toBe(ErrorCode::NotFound);
});
