<?php

namespace App\Http\Resources;

use App\Services\AuditSanitizer;
use App\Support\AuditEventContract;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAuditLogDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $sanitizer = app(AuditSanitizer::class);
        $base = (new AdminAuditLogListResource($this->resource))->toArray($request);
        $specializedType = is_string($this->specialized_log_type)
            && in_array($this->specialized_log_type, AuditEventContract::SPECIALIZED_LOG_TYPES, true)
            ? $this->specialized_log_type
            : null;

        return [
            ...$base,
            'old_values' => $sanitizer->snapshot((string) $this->action, $this->old_values),
            'new_values' => $sanitizer->snapshot((string) $this->action, $this->new_values),
            'metadata' => $sanitizer->metadata((string) $this->action, $this->metadata),
            'specialized_log_type' => $specializedType,
            'specialized_log_id' => $specializedType === null ? null : $sanitizer->safeIdentifier($this->specialized_log_id, 120),
            'ip_address' => $sanitizer->anonymizeIp($this->ip_address),
        ];
    }
}
