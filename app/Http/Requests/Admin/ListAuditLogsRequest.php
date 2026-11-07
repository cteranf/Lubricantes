<?php

namespace App\Http\Requests\Admin;

use App\Support\AuditEventContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListAuditLogsRequest extends FormRequest
{
    private const FILTERS = [
        'date_from', 'date_to', 'action', 'category', 'result', 'actor_type', 'actor_id',
        'auditable_type', 'auditable_id', 'request_id', 'correlation_id',
    ];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'action' => ['sometimes', Rule::in(AuditEventContract::ACTIONS)],
            'category' => ['sometimes', Rule::in([
                AuditEventContract::CATEGORY_AUTHENTICATION,
                AuditEventContract::CATEGORY_ACCESS,
                AuditEventContract::CATEGORY_USER_SECURITY,
                AuditEventContract::CATEGORY_SYSTEM,
            ])],
            'result' => ['sometimes', Rule::in(AuditEventContract::RESULTS)],
            'actor_type' => ['sometimes', Rule::in(AuditEventContract::ACTOR_TYPES)],
            'actor_id' => ['sometimes', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/D'],
            'auditable_type' => ['sometimes', Rule::in(AuditEventContract::AUDITABLE_TYPES)],
            'auditable_id' => ['sometimes', 'string', 'max:120', 'regex:/\A[a-zA-Z0-9._:\-]+\z/D'],
            'request_id' => ['sometimes', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD'],
            'correlation_id' => ['sometimes', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = [...self::FILTERS, 'page', 'per_page'];
            foreach (array_keys($this->query->all()) as $key) {
                if (! is_string($key) || ! in_array($key, $allowed, true)) {
                    $validator->errors()->add('filters', 'Se recibió un filtro no permitido.');
                }
            }
        });
    }

    public function filters(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::FILTERS));
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function pageSize(): int
    {
        return (int) ($this->validated('per_page') ?? 25);
    }
}
