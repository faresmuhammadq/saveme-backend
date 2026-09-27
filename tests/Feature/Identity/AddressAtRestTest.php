<?php

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserKey;
use Illuminate\Support\Facades\DB;

/*
 * No readable address or password is stored anywhere, and destroying a
 * person's data key makes their sealed values unreadable (security baseline,
 * What is protected at rest; Deletion rule 3). Registration through the API
 * is covered again in RegistrationTest.
 */

/**
 * Every row of every Identity table, as text, for a plain substring search.
 */
function identityTablesAsText(): string
{
    $tables = ['users', 'user_keys', 'profiles', 'device_sessions', 'identity_links', 'audit_entries', 'sign_in_failures'];

    return collect($tables)
        ->map(fn (string $table): string => (string) DB::scalar("select coalesce(string_agg(row_to_json(t)::text, ' '), '') from {$table} t"))
        ->implode("\n");
}

test('a user leaves no readable address or password at rest', function () {
    User::factory()->withEmail('ada@example.test')->withPassword('correct horse')->create();

    expect(identityTablesAsText())->not->toContain('ada@example.test')->not->toContain('correct horse');
});

test('the address is recoverable only while the data key exists', function () {
    $user = User::factory()->withEmail('ada@example.test')->create();
    $cipher = app(AttributeCipher::class);

    expect($cipher->open($user->id, $user->email_ciphertext))->toBe('ada@example.test');

    UserKey::query()->whereKey($user->id)->delete();
    app()->forgetScopedInstances();

    expect(fn () => app(AttributeCipher::class)->open($user->id, $user->email_ciphertext))->toThrow(RuntimeException::class);
});

test('a saved display name is never stored readable', function () {
    person();
    $credential = signInAs()->json('data.credential');

    saveProfile($credential, profileBody(['display_name' => 'Ada Lovelace']))->assertOk();

    expect(identityTablesAsText())->not->toContain('Ada Lovelace');
});
