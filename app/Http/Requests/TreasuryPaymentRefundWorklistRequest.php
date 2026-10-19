<?php

namespace App\Http\Requests;

use App\Services\TreasuryPaymentRefundWorklistService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class TreasuryPaymentRefundWorklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $query = (string) $this->server->get('QUERY_STRING', '');
        if ($query === '') {
            return;
        }
        $seen = [];
        foreach (explode('&', $query) as $segment) {
            if ($segment === '' || ! str_contains($segment, '=')) {
                $this->invalidFilters();
            }
            $name = urldecode(explode('=', $segment, 2)[0]);
            if ($name === '' || str_contains($name, '[') || str_contains($name, ']')
                || ! in_array($name, $this->fields(), true) || in_array($name, $seen, true)) {
                $this->invalidFilters();
            }
            $seen[] = $name;
        }
    }

    private function fields(): array
    {
        return $this->routeIs('treasury.orders.refund-state') ? [] : ['order', 'payment_method', 'refund_status', 'date_from', 'date_to', 'page', 'per_page'];
    }

    public function rules(): array
    {
        if ($this->fields() === []) {
            return [];
        }

        return [
            'order' => ['sometimes', 'required', 'string', 'regex:/\A#?0*[1-9][0-9]*\z/', function ($attribute, $value, $fail): void {
                if (! is_string($value)) {
                    return;
                }
                $id = ltrim(ltrim($value, '#'), '0');
                if ((string) (int) $id !== $id) {
                    $fail('Filtro inválido.');
                }
            }],
            'payment_method' => ['sometimes', 'required', 'string', Rule::in(['transferencia', 'card', 'contra_entrega', 'pago_en_sede'])],
            'refund_status' => ['sometimes', 'required', 'string', Rule::in(['not_started', 'pending', 'completed', 'manual_review'])],
            'date_from' => ['sometimes', 'required', 'string', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'string', 'date_format:Y-m-d', ...($this->query->has('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.TreasuryPaymentRefundWorklistService::MAX_PER_PAGE],
        ];
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (array_diff(array_keys($this->query->all()), $this->fields()) !== [] || $this->allFiles() !== []
                || $this->request->count() !== 0 || ($this->isJson() && $this->json()->count() !== 0)) {
                $validator->errors()->add('request', 'La consulta solo acepta filtros autorizados.');
            }
            foreach ($this->query->all() as $value) {
                if (! is_string($value)) {
                    $validator->errors()->add('request', 'Los filtros deben ser escalares.');
                }
            }
        });
    }

    protected function failedValidation(Validator $validator): never
    {
        $this->invalidFilters();
    }

    private function invalidFilters(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'Filtros inválidos.', 'errors' => ['request' => ['Revisa los filtros autorizados.']]], 422)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }
}
