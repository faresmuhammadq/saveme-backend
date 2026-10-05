<div class="flex flex-col gap-5">
    <x-admin::card>
        <form class="flex flex-col gap-4" wire:submit="find" role="search">
            <x-admin::input
                name="term" :label="__('admin::accounts.find.label')" :hint="__('admin::accounts.find.hint')"
                :error="$errors->first('term')" dir="ltr" autocomplete="off" spellcheck="false"
                wire:model="term" data-test="account-search"
            />
            <div>
                <x-admin::button type="submit" icon="search" data-test="account-find">{{ __('admin::accounts.find.submit') }}</x-admin::button>
            </div>
        </form>
    </x-admin::card>

    @if ($missed)
        <x-admin::alert tone="info" data-test="no-match">{{ __('admin::accounts.find.no_match') }}</x-admin::alert>
    @endif

    @if ($budgetMinutes !== null)
        <x-admin::alert tone="warning" data-test="lookup-budget">{{ __('admin::accounts.budget_spent', ['minutes' => $budgetMinutes]) }}</x-admin::alert>
    @endif
</div>
