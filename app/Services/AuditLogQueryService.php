<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditLogQueryService
{
    public const LIST_COLUMNS = [
        'id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'category', 'result', 'failure_code',
        'auditable_type', 'auditable_id', 'route_name', 'http_method', 'request_id', 'correlation_id',
        'retention_category', 'created_at',
    ];

    public const DETAIL_COLUMNS = [
        ...self::LIST_COLUMNS, 'old_values', 'new_values', 'metadata', 'specialized_log_type',
        'specialized_log_id', 'ip_address',
    ];

    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = AuditLog::query()->select(self::LIST_COLUMNS);

        foreach (['action', 'category', 'result', 'actor_type', 'actor_id', 'auditable_type', 'auditable_id', 'request_id', 'correlation_id'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (array_key_exists('date_from', $filters)) {
            $query->where('occurred_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'])->startOfDay());
        }
        if (array_key_exists('date_to', $filters)) {
            $query->where('occurred_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'])->addDay()->startOfDay());
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function findDetail(string $id): AuditLog
    {
        if (preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) !== 1) {
            abort(404);
        }

        return AuditLog::query()->select(self::DETAIL_COLUMNS)->whereKey($id)->firstOrFail();
    }
}
