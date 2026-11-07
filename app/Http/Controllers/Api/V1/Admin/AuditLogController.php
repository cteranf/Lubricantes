<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Http\Resources\AdminAuditLogDetailResource;
use App\Http\Resources\AdminAuditLogListResource;
use App\Models\User;
use App\Services\AuditLogQueryService;
use App\Services\AuditService;
use App\Support\AuditEventContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(
        private AuditLogQueryService $reader,
        private AuditService $audit
    ) {}

    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        $page = $request->pageNumber();
        $perPage = $request->pageSize();
        $paginator = $this->reader->paginate($request->filters(), $page, $perPage);
        $data = AdminAuditLogListResource::collection($paginator->getCollection())->resolve($request);
        $payload = [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'actor_name_semantics' => 'actor_id es la referencia histórica; el nombre, si se consulta en detalle, es el nombre actual.',
        ];

        $this->audit->recordUser(
            AuditEventContract::AUDIT_LOGS_VIEWED,
            $request->user(),
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['scope' => 'list', 'page' => $page, 'per_page' => $perPage],
            request: $request
        );

        return response()->json($payload);
    }

    public function show(string $auditLog, Request $request): JsonResponse
    {
        $entry = $this->reader->findDetail($auditLog);
        $actor = $entry->actor_type === AuditEventContract::ACTOR_USER && $entry->actor_id !== null
            ? User::query()->select(['id', 'name'])->find($entry->actor_id)
            : null;
        $payload = [
            'data' => [
                ...(new AdminAuditLogDetailResource($entry))->resolve($request),
                'actor' => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
            ],
            'actor_name_semantics' => 'actor_id es la referencia histórica; el nombre mostrado corresponde al usuario actual y puede diferir del estado histórico.',
        ];

        $this->audit->recordUser(
            AuditEventContract::AUDIT_LOG_VIEWED,
            $request->user(),
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['scope' => 'detail', 'audit_log_id' => (string) $entry->id],
            request: $request
        );

        return response()->json($payload);
    }
}
