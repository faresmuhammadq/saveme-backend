<?php

namespace App\Modules\Wallets\Http\Controllers\Recurring;

use App\Http\Api\Envelope;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Recurring\RecurringQueryService;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Http\Requests\Recurring\RuleId;
use App\Modules\Wallets\Http\Requests\Recurring\RuleListRequest;
use App\Modules\Wallets\Http\Requests\Recurring\RuleVersionRequest;
use App\Modules\Wallets\Http\Requests\Recurring\SaveRecurringRuleRequest;
use App\Modules\Wallets\Http\Requests\WalletId;
use App\Modules\Wallets\Http\Resources\RecurringRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecurringRuleController
{
    public function index(Request $request, string $id, RecurringQueryService $recurring): JsonResponse
    {
        $walletId = WalletId::assert($id);
        $list = RuleListRequest::from($request);

        /** @var 'created_at'|'start_date' $sort */
        $sort = $list->sort;
        $page = $recurring->listRules($this->callerId($request), $walletId, $list->query->filters, $sort, $list->query->descending, $list->after, $list->query->limit);

        return Envelope::collection(
            array_map(fn (RuleView $view): array => RecurringRuleResource::toArray($view), $page['items']),
            $list->query->cursorFor($page['next']),
            $list->query->limit,
        );
    }

    public function show(Request $request, string $id, string $rid, RecurringQueryService $recurring): JsonResponse
    {
        return Envelope::ok(RecurringRuleResource::toArray($recurring->findRule($this->callerId($request), WalletId::assert($id), RuleId::assert($rid))));
    }

    public function upsert(SaveRecurringRuleRequest $request, RecurringRuleService $rules): JsonResponse
    {
        $saved = $rules->save(
            $this->callerId($request),
            $request->walletId(),
            $request->ruleId(),
            $request->values(),
            $request->version(),
            RecurringRuleResource::toArray(...),
        );

        return Envelope::ok(RecurringRuleResource::toArray($saved->view), status: $saved->created ? 201 : 200);
    }

    public function destroy(RuleVersionRequest $request, RecurringRuleService $rules): JsonResponse
    {
        $view = $rules->delete($this->callerId($request), $request->walletId(), $request->ruleId(), $request->version(), RecurringRuleResource::toArray(...));

        return Envelope::ok(RecurringRuleResource::toArray($view));
    }

    public function materialise(Request $request, string $id, string $rid, RecurringRuleService $rules): JsonResponse
    {
        $view = $rules->materialise($this->callerId($request), WalletId::assert($id), RuleId::assert($rid), RecurringRuleResource::toArray(...));

        return Envelope::ok(RecurringRuleResource::toArray($view));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
