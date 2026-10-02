<?php

/*
|--------------------------------------------------------------------------
| The user scope counter (spec 013, R-5)
|--------------------------------------------------------------------------
|
| The shared sequence is gone: nothing under app/ draws from `sync_change_seq`
| or calls nextval(), and Wallets reaches the user counter only through
| Identity's Application service.
|
*/

test('no class under app/ references sync_change_seq or nextval(', function () {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // The migrations that created and dropped the sequence are history.
        if (str_contains($file->getPathname(), '/Migrations/')) {
            continue;
        }

        if (str_contains($source, 'sync_change_seq') || str_contains($source, 'nextval(')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([], 'ADR-0008 rules 2-5: the user scope counter is users.change_seq; found: '.implode(', ', $offenders));
});

arch('Wallets never uses Identity Infrastructure')
    ->expect('App\Modules\Wallets')
    ->not->toUse('App\Modules\Identity\Infrastructure');

arch('Sync references no other module\'s Infrastructure or Http')
    ->expect('App\Modules\Sync')
    ->not->toUse([
        'App\Modules\Identity\Infrastructure', 'App\Modules\Identity\Http',
        'App\Modules\Wallets\Infrastructure', 'App\Modules\Wallets\Http',
    ]);
