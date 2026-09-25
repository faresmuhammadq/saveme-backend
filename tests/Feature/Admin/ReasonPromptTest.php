<?php

/*
| An audited action cannot run without a reason (spec 006, FR-018; invariant
| "An audited action cannot run without a non-blank reason").
*/

use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Admin\Fixtures\AuditedFixture;

it('opens the prompt instead of running the action', function () {
    Livewire::test(AuditedFixture::class)
        ->call('start', 'CLM-2046')
        ->assertSet('reasonOpen', true)
        ->assertSet('calls', []);
});

it('refuses a blank reason, keeps the prompt open and does not run the action', function (string $reason) {
    Livewire::test(AuditedFixture::class)
        ->call('start', 'CLM-2046')
        ->call('confirmReason', $reason)
        ->assertHasErrors('reason')
        ->assertSet('reasonOpen', true)
        ->assertSet('calls', []);
})->with(['empty' => '', 'spaces' => '   ']);

it('refuses a reason over 500 characters', function () {
    Livewire::test(AuditedFixture::class)
        ->call('start', 'CLM-2046')
        ->call('confirmReason', str_repeat('a', 501))
        ->assertHasErrors('reason')
        ->assertSet('calls', []);
});

it('runs the action once with the trimmed reason and closes the prompt', function () {
    Livewire::test(AuditedFixture::class)
        ->call('start', 'CLM-2046')
        ->call('confirmReason', '  Reference verified.  ')
        ->assertHasNoErrors()
        ->assertSet('reasonOpen', false)
        ->assertSet('reasonMethod', null)
        ->assertSet('calls', [['id' => 'CLM-2046', 'reason' => 'Reference verified.']]);
});

it('does nothing when confirming without a pending action', function () {
    Livewire::test(AuditedFixture::class)
        ->call('confirmReason', 'Reference verified.')
        ->assertSet('calls', []);
});

it('rejects a direct browser call to the audited method', function () {
    Livewire::test(AuditedFixture::class)->call('approve', 'CLM-2046');
})->throws(MethodNotFoundException::class);

it('rejects a browser attempt to redirect the pending action', function () {
    Livewire::test(AuditedFixture::class)
        ->call('start', 'CLM-2046')
        ->set('reasonMethod', 'publicTarget');
})->throws(CannotUpdateLockedPropertyException::class);

it('refuses an audited target that is public', function () {
    Livewire::test(AuditedFixture::class)->call('startPublicTarget');
})->throws(LogicException::class, 'must be protected');

it('refuses an audited target without an AuditReason parameter', function () {
    Livewire::test(AuditedFixture::class)->call('startUntypedTarget');
})->throws(LogicException::class, 'must take AuditReason');

it('clears the pending action on cancel', function () {
    Livewire::test(AuditedFixture::class)
        ->call('start', 'CLM-2046')
        ->call('cancelReason')
        ->assertSet('reasonOpen', false)
        ->assertSet('reasonAction', null)
        ->assertSet('reasonArguments', []);
});
