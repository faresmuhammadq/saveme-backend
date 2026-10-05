<?php

use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Admin\Http\Livewire\AuditLog;
use App\Modules\Admin\Http\Livewire\ClaimReview;
use App\Modules\Admin\Http\Livewire\FindAccount;
use App\Modules\Admin\Http\Livewire\PlanEditor;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * Nothing protected reaches a log line from the B12 screens (spec 021,
 * FR-062; constitution P-15): not the claim address, the typed address, a
 * reason, a review note, a price or a display name.
 */

it('writes no log record that carries a protected value', function () {
    $this->freezeTime();
    ['operator' => $operator] = makeOperator('enrolled', ['name' => 'Layla Display-Name']);
    signInOperator($operator);

    $records = [];
    Log::listen(function ($event) use (&$records): void {
        $records[] = json_encode([$event->level, $event->message, $event->context]);
    });

    $user = User::factory()->withEmail('typed.lookup@example.test')->create();
    $claim = PaymentClaim::factory()->forAddress('claim.payer@example.test')->create();
    $plan = Plan::query()->where('code', 'plus_monthly')->sole();

    Livewire::test(ClaimReview::class, ['claim' => $claim->id]);

    Livewire::test(FindAccount::class)->set('term', 'Typed.Lookup@example.test')->call('find');

    $grantPlan = (string) Plan::query()->where('code', 'plus_monthly')->value('id');
    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->set('grantPlan', $grantPlan)->set('grantDuration', '30')->set('grantReason', 'ZQ-REASON-GRANT-5521')->call('confirmGrant');

    Livewire::test(PlanEditor::class, ['plan' => $plan->id])
        ->set('draft.price_amount', '77777')->call('review')->set('reason', 'ZQ-REASON-PLAN-8841')->call('confirmSave');

    Livewire::test(AuditLog::class);

    expect(DB::table('subscriptions')->where('user_id', $user->id)->count())->toBe(1)
        ->and($plan->refresh()->prices[0]['amount_minor'])->toBe(77777)
        ->and(DB::table('audit_entries')->where('event', 'audit_read')->count())->toBe(1);

    $log = implode("\n", $records);

    foreach (['claim.payer@example.test', 'typed.lookup@example.test', 'Typed.Lookup', 'ZQ-REASON-GRANT-5521', 'ZQ-REASON-PLAN-8841', '77777', 'Layla Display-Name'] as $protected) {
        expect($log)->not->toContain($protected);
    }
});
