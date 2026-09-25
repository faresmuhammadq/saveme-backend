<?php

namespace Tests\Feature\Admin\Fixtures;

use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresReason;
use Livewire\Component;

/**
 * A back-office screen with one audited action, for ReasonPromptTest.
 */
class AuditedFixture extends Component
{
    use RequiresReason;

    /** @var list<array{id: string, reason: string}> */
    public array $calls = [];

    public function start(string $id): void
    {
        $this->requireReason('fixture.approve', 'approve', [$id]);
    }

    public function startPublicTarget(): void
    {
        $this->requireReason('fixture.approve', 'publicTarget');
    }

    public function startUntypedTarget(): void
    {
        $this->requireReason('fixture.approve', 'untypedTarget');
    }

    protected function approve(string $id, AuditReason $reason): void
    {
        $this->calls[] = ['id' => $id, 'reason' => $reason->value()];
    }

    public function publicTarget(AuditReason $reason): void
    {
        //
    }

    protected function untypedTarget(string $reason): void
    {
        //
    }

    public function render(): string
    {
        return '<div><x-admin::reason-prompt action-key="fixture.approve" :error="$errors->first(\'reason\')" /></div>';
    }
}
