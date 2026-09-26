<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\Deletion\CancelDeletion;
use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Requests\PasswordConfirmationRequest;
use App\Modules\Identity\Http\Resources\Instants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeletionRequestController
{
    use ResolvesCaller;

    public function store(PasswordConfirmationRequest $request, RequestDeletion $deletion): JsonResponse
    {
        $scheduledFor = $deletion->handle($this->callerId($request), $request->string('password')->toString());

        return Envelope::ok(['state' => 'pending_deletion', 'deletion_scheduled_for' => Instants::format($scheduledFor)]);
    }

    public function destroy(Request $request, CancelDeletion $cancel): Response
    {
        $cancel->cancel($this->callerId($request), 'explicit');

        return Envelope::noContent();
    }
}
