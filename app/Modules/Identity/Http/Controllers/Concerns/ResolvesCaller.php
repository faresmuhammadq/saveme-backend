<?php

namespace App\Modules\Identity\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * The authenticated caller as identifiers only. Controllers hand these to
 * application services and never touch the Identity model (P-02: Http does
 * not import its own Infrastructure).
 */
trait ResolvesCaller
{
    protected function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }

    protected function currentSessionId(Request $request): string
    {
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return (string) $user->currentAccessToken()->getKey();
    }
}
