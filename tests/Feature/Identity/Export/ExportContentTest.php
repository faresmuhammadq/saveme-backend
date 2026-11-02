<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;
use Illuminate\Support\Facades\Storage;

/*
 * What an export holds, and never holds (spec 022, FR-001 to FR-007,
 * SC-002): a person's own copy of what they entered and can see.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->world = exportWorld();
    $this->archive = generateExport($this->world['d']);
});

test('the archive holds the manifest, every record file and the spreadsheet', function () {
    expect($this->archive['files'])->toEqualCanonicalizing([
        'manifest.json', 'account.json', 'profile.json', 'devices.json', 'subscriptions.json', 'categories.json', 'budgets.json', 'shared_budgets.json',
        'memberships.json', 'invitations.json', 'wallets.json', 'transactions.json', 'recurring_rules.json', 'occurrences.json', 'transactions.csv',
    ]);
});

test('the manifest names the format, the scope and a count per file that matches the file', function () {
    $manifest = $this->archive['json']['manifest.json'];

    expect($manifest['format'])->toBe(1)
        ->and($manifest['scope'])->toBe('account')
        ->and($manifest['wallet_id'])->toBeNull()
        ->and($manifest['generated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');

    foreach ($this->archive['json'] as $name => $content) {
        if ($name !== 'manifest.json') {
            expect($manifest['files'][$name])->toBe(count($content), $name);
        }
    }
    expect($manifest['files']['transactions.csv'])->toBe(5);
});

test('the wallets the person belongs to are in, with all their entries; the one they left is not', function () {
    $wallets = collect($this->archive['json']['wallets.json'])->keyBy('id');
    $transactions = collect($this->archive['json']['transactions.json']);

    expect($wallets->keys()->all())->toEqualCanonicalizing([$this->world['w']->id, $this->world['r']->id, $this->world['s']->id])
        ->and($wallets[$this->world['w']->id]['role'])->toBe('owner')
        ->and($wallets[$this->world['w']->id]['member_count'])->toBe(3)
        ->and($wallets[$this->world['r']->id]['role'])->toBe('viewer')
        ->and($transactions->where('wallet_id', $this->world['w']->id))->toHaveCount(3)
        ->and($transactions->where('wallet_id', $this->world['r']->id))->toHaveCount(1)
        ->and($transactions->where('wallet_id', $this->world['l']->id))->toHaveCount(0)
        ->and($this->archive['raw']['transactions.json'])->not->toContain('In left wallet')
        ->and($this->archive['raw']['wallets.json'])->not->toContain('Left Behind');
});

test('other members\' entries are marked other_member, the person\'s own me', function () {
    $authors = collect($this->archive['json']['transactions.json'])->pluck('author', 'id');

    expect($authors[$this->world['mine']->transaction->id])->toBe('me')
        ->and($authors[$this->world['fromPat']->transaction->id])->toBe('other_member')
        ->and($authors[$this->world['fromQuinn']->transaction->id])->toBe('other_member')
        ->and($authors[$this->world['inR']->transaction->id])->toBe('other_member');
});

test('no other person\'s id, name, address or category list appears anywhere in the archive', function () {
    $everything = implode("\n", $this->archive['raw']);

    foreach ([
        $this->world['p']->id, $this->world['q']->id,
        'Pat Partner', 'Quinn Queue', 'pat@example.test', 'quinn@example.test', 'PatUnusedCategory',
    ] as $forbidden) {
        expect($everything)->not->toContain($forbidden);
    }
    expect($everything)->toContain('ada@example.test')->and($everything)->toContain('Ada Lovelace');
});

test('a category on another member\'s entry shows as the label the person already sees through the wallet', function () {
    $entry = collect($this->archive['json']['transactions.json'])->firstWhere('id', $this->world['fromPat']->transaction->id);

    expect($entry['category_id'])->toBe($this->world['patCategory']->id)
        ->and($entry['category'])->toBe('PatSecretCategory');
});

test('the person\'s own categories are exported, and internal columns never are', function () {
    $own = collect($this->archive['json']['categories.json'])->pluck('name');

    expect($own->all())->toBe(['Groceries']);

    $everything = implode("\n", $this->archive['raw']);
    foreach (['change_seq', 'member_change_seq', 'purged_change_seq', 'auto_confirm_from', 'postings', 'password', 'device_id', 'token'] as $internal) {
        expect($everything)->not->toContain($internal);
    }
});

test('the membership history is the person\'s own, a left wallet a tombstone', function () {
    $memberships = collect($this->archive['json']['memberships.json']);

    expect($memberships)->toHaveCount(4)
        ->and($memberships->whereNotNull('deleted_at')->pluck('wallet_id')->all())->toBe([$this->world['l']->id])
        ->and($memberships->whereNotNull('deleted_at')->first())->toHaveKeys(['id', 'deleted_at', 'wallet_id'])
        ->and(array_keys($memberships->whereNotNull('deleted_at')->first()))->toHaveCount(3);
});

test('a deleted entry is a tombstone with only its id, instant and wallet, and is not in the spreadsheet', function () {
    $service = app(TransactionService::class);
    $deleted = $this->world['mine']->transaction;
    $service->delete($this->world['d']->id, $this->world['w']->id, $deleted->id, $deleted->version, presentTransaction());

    $archive = generateExport($this->world['d']);
    $tombstone = collect($archive['json']['transactions.json'])->firstWhere('id', $deleted->id);

    expect(array_keys($tombstone))->toEqualCanonicalizing(['id', 'deleted_at', 'wallet_id'])
        ->and($archive['raw']['transactions.csv'])->not->toContain($deleted->id)
        ->and($archive['raw']['transactions.csv'])->not->toContain('Ada lunch');
});

test('the invitations the person sent carry neither the invited address nor the token', function () {
    $sent = pendingInvitation($this->world['d']->id, $this->world['w']->id, 'secret.invitee@example.test');

    $archive = generateExport($this->world['d']);
    $everything = implode("\n", $archive['raw']);

    expect($archive['json']['invitations.json'])->toHaveCount(1)
        ->and($archive['json']['invitations.json'][0]['id'])->toBe($sent['id'])
        ->and($archive['json']['invitations.json'][0]['status'])->toBe('pending')
        ->and($everything)->not->toContain('secret.invitee')
        ->and($everything)->not->toContain($sent['token']);
});

test('the spreadsheet is UTF-8 with a BOM and carries integer minor units, the author and no other member', function () {
    $csv = $this->archive['raw']['transactions.csv'];
    $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\r\n", substr($csv, 3))));

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($rows[0])->toBe(['date', 'wallet', 'wallet_id', 'kind', 'amount_minor', 'currency', 'category', 'counter_wallet', 'description', 'memo', 'counterparty', 'cleared', 'author', 'transaction_id', 'created_at', 'updated_at'])
        ->and(array_column(array_slice($rows, 1), 4))->toEqualCanonicalizing(['2500', '4000', '900', '1200', '300']);
});

test('an account with nothing in it exports empty arrays and a header-only spreadsheet', function () {
    $empty = namedPerson('empty@example.test', null);

    $archive = generateExport($empty);

    expect($archive['json']['wallets.json'])->toBe([])
        ->and($archive['json']['transactions.json'])->toBe([])
        ->and($archive['json']['categories.json'])->toBe([])
        ->and($archive['json']['account.json'])->toHaveCount(1)
        ->and(substr($archive['raw']['transactions.csv'], 3))->toBe("date,wallet,wallet_id,kind,amount_minor,currency,category,counter_wallet,description,memo,counterparty,cleared,author,transaction_id,created_at,updated_at\r\n");
});

test('a description that looks like a formula is neutralised in the spreadsheet and kept as typed in the JSON', function () {
    recordTransaction($this->world['d']->id, $this->world['s']->id, transactionBody(['description' => '=HYPERLINK("http://x")']));

    $archive = generateExport($this->world['d']);

    expect($archive['raw']['transactions.csv'])->toContain("\"'=HYPERLINK(\"\"http://x\"\")\"")
        ->and(collect($archive['json']['transactions.json'])->pluck('description')->all())->toContain('=HYPERLINK("http://x")');
});

test('a wallet export holds only that wallet, the labels its entries use, and nothing user-scoped', function () {
    $archive = generateExport($this->world['d'], 'wallet', $this->world['w']->id);

    expect($archive['files'])->toEqualCanonicalizing([
        'manifest.json', 'wallets.json', 'transactions.json', 'recurring_rules.json', 'occurrences.json', 'categories.json', 'transactions.csv',
    ])
        ->and($archive['json']['manifest.json']['scope'])->toBe('wallet')
        ->and($archive['json']['manifest.json']['wallet_id'])->toBe($this->world['w']->id)
        ->and(collect($archive['json']['wallets.json'])->pluck('id')->all())->toBe([$this->world['w']->id])
        ->and($archive['json']['transactions.json'])->toHaveCount(3)
        ->and(collect($archive['json']['categories.json'])->pluck('name')->all())->toContain('Groceries', 'PatSecretCategory')
        ->and(collect($archive['json']['categories.json'])->pluck('name')->all())->not->toContain('PatUnusedCategory')
        ->and(implode("\n", $archive['raw']))->not->toContain('ada@example.test');
});

test('a viewer\'s wallet export holds that wallet only', function () {
    $archive = generateExport($this->world['d'], 'wallet', $this->world['r']->id);

    expect(collect($archive['json']['wallets.json'])->pluck('id')->all())->toBe([$this->world['r']->id])
        ->and($archive['json']['transactions.json'])->toHaveCount(1)
        ->and($archive['json']['transactions.json'][0]['author'])->toBe('other_member');
});
