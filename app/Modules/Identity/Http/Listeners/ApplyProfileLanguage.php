<?php

namespace App\Modules\Identity\Http\Listeners;

use App\Modules\Identity\Domain\Ports\ProfileRepository;
use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * When a signed-in request names no language, it is answered in the person's
 * profile language (spec 008, FR-008, R-5). Runs as the credential is
 * accepted, before the response's Content-Language is written.
 */
final class ApplyProfileLanguage
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly Request $request,
    ) {}

    public function handle(TokenAuthenticated $event): void
    {
        if (trim((string) $this->request->headers->get('Accept-Language', '')) !== '') {
            return;
        }

        $userId = $event->token->getAttribute('user_id');

        if (is_string($userId)) {
            app()->setLocale($this->profiles->localeOf($userId));
        }
    }
}
