<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminAuditLogListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'action' => $this->action,
            'category' => $this->category,
            'result' => $this->result,
            'failure_code' => $this->failure_code,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'route_name' => $this->route_name,
            'http_method' => $this->http_method,
            'request_id' => $this->request_id,
            'correlation_id' => $this->correlation_id,
            'retention_category' => $this->retention_category,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
