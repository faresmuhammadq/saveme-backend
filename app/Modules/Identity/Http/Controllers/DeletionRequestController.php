<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\Deletion\CancelDeletion;
use App\Modules\Identity\Application\Deletion\DeletionPlanner;
use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Requests\DeletionRequestRequest;
use App\Modules\Identity\Http\Resources\DeletionPlanResource;
use App\Modules\Identity\Http\Resources\Instants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeletionRequestController
{
    use ResolvesCaller;

    public function show(Request $request, DeletionPlanner $planner): JsonResponse
    {
        return Envelope::ok(DeletionPlanResource::toArray($planner->plan($this->callerId($request))));
    }

    public function store(DeletionRequestRequest $request, RequestDeletion $deletion, DeletionPlanner $planner): JsonResponse
    {
        $callerId = $this->callerId($request);
        $scheduledFor = $deletion->handle($callerId, $request->string('password')->toString(), $request->successors());

        return Envelope::ok([
            'state' => 'pending_deletion',
            'deletion_scheduled_for' => Instants::format($scheduledFor),
            'successors' => DeletionPlanResource::successors($planner->plan($callerId)),
        ]);
    }

    public function destroy(Request $request, CancelDeletion $cancel): Response
    {
        $cancel->cancel($this->callerId($request), 'explicit');

        return Envelope::noContent();
    }
}
