<?php

namespace App\Http\Requests\Admin;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Services\MoneyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListAdminOrdersRequest extends FormRequest
{
    private const SORT_COLUMNS = ['created_at', 'id', 'total'];

    private const PAYMENT_METHODS = ['card', 'transferencia', 'contra_entrega', 'pago_en_sede'];

    private const PAYMENT_STATUSES = ['pending', 'approved', 'rejected', 'refund_pending', 'refunded'];

    private const FULFILLMENT_STATUSES = [
        Order::FULFILLMENT_RESERVED,
        Order::FULFILLMENT_PREPARING,
        Order::FULFILLMENT_READY,
        Order::FULFILLMENT_DELIVERED,
        Order::FULFILLMENT_CANCELED,
    ];

    private const DELIVERY_TYPES = ['delivery', 'pickup'];

    private const TEXT_FILTERS = ['customer'];

    private const FILTER_KEYS = [
        'search', 'order_id', 'customer', 'total_min', 'total_max', 'fulfillment_status',
        'payment_method', 'payment_status', 'warehouse_id', 'reservation_status',
        'delivery_type', 'date_from', 'date_to',
    ];

    private const QUERY_KEYS = [
        'page', 'per_page', 'sort', 'direction', ...self::FILTER_KEYS,
    ];

    private bool $rawQueryParameterNamesAllowed = true;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function validationData(): array
    {
        $rawQuery = (string) $this->server('QUERY_STRING', '');
        $this->rawQueryParameterNamesAllowed = $this->hasAllowedRawQueryParameterNames($rawQuery);

        if (! $this->rawQueryParameterNamesAllowed) {
            return [];
        }

        parse_str($rawQuery, $query);

        foreach (self::QUERY_KEYS as $key) {
            if (! array_key_exists($key, $query) || ! is_string($query[$key])) {
                continue;
            }

            if (in_array($key, self::TEXT_FILTERS, true)) {
                $query[$key] = trim($query[$key]);
            } elseif ($query[$key] === '' && ! in_array($key, ['page', 'per_page'], true)) {
                $query[$key] = null;
            }
        }

        // Match ConvertEmptyStringsToNull while retaining strict page-size validation.
        if (array_key_exists('per_page', $query) && $query['per_page'] === '') {
            $query['per_page'] = null;
        }

        return $query;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'string', 'regex:/\A[1-9][0-9]*\z/D'],
            'per_page' => ['bail', 'sometimes', 'required', 'string', 'regex:/\A(?:15|25|50|100)\z/D'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'order_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]*\z/D', 'max:19'],
            'customer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'total_min' => ['sometimes', 'nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D'],
            'total_max' => ['sometimes', 'nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D'],
            'fulfillment_status' => ['sometimes', 'nullable', 'string', Rule::in(self::FULFILLMENT_STATUSES)],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(self::PAYMENT_METHODS)],
            'payment_status' => ['sometimes', 'nullable', 'string', Rule::in(self::PAYMENT_STATUSES)],
            'warehouse_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]*\z/D', 'max:19', Rule::exists('warehouses', 'id')],
            'reservation_status' => ['sometimes', 'nullable', 'string', Rule::in(InventoryReservation::STATUSES)],
            'delivery_type' => ['sometimes', 'nullable', 'string', Rule::in(self::DELIVERY_TYPES)],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->rawQueryParameterNamesAllowed) {
                $validator->errors()->add('query', 'Se recibió un parámetro no permitido.');

                return;
            }

            foreach (array_keys($this->validationData()) as $key) {
                if (! is_string($key) || ! in_array($key, self::QUERY_KEYS, true)) {
                    $validator->errors()->add('query', 'Se recibió un parámetro no permitido.');
                }
            }

            $data = $this->validationData();
            if (is_string($data['search'] ?? null)
                && $data['search'] !== ''
                && trim($data['search']) === '') {
                $validator->errors()->add('search', 'La búsqueda no puede contener únicamente espacios.');
            }

            $this->validateIntegerRange($validator, $data['page'] ?? null, 'page');
            $this->validateIntegerRange($validator, $data['order_id'] ?? null, 'order_id');
            $this->validateIntegerRange($validator, $data['warehouse_id'] ?? null, 'warehouse_id');

            $money = app(MoneyService::class);
            foreach (['total_min', 'total_max'] as $key) {
                $value = $data[$key] ?? null;
                if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D', $value) === 1) {
                    $money->toMinor($value);
                }
            }

            if (is_string($data['total_min'] ?? null)
                && is_string($data['total_max'] ?? null)
                && $this->isValidMoney($data['total_min'])
                && $this->isValidMoney($data['total_max'])
                && $money->toMinor($data['total_min']) > $money->toMinor($data['total_max'])) {
                $validator->errors()->add('total_min', 'El mínimo no puede superar el máximo.');
            }

            if (is_string($data['date_from'] ?? null)
                && is_string($data['date_to'] ?? null)
                && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $data['date_from']) === 1
                && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $data['date_to']) === 1
                && $data['date_from'] > $data['date_to']) {
                $validator->errors()->add('date_from', 'La fecha inicial no puede superar la fecha final.');
            }
        });
    }

    private function hasAllowedRawQueryParameterNames(string $rawQuery): bool
    {
        if ($rawQuery === '') {
            return true;
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $rawQuery) === 1) {
            return false;
        }

        $separators = (string) ini_get('arg_separator.input');
        $separators = $separators !== '' ? $separators : '&';
        $segments = preg_split('/['.preg_quote($separators, '/').']/', $rawQuery);
        if (! is_array($segments) || $segments === []) {
            return false;
        }

        $seen = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                return false;
            }

            $encodedName = explode('=', $segment, 2)[0];
            if ($encodedName === '') {
                return false;
            }

            $name = urldecode($encodedName);
            if (! in_array($name, self::QUERY_KEYS, true) || isset($seen[$name])) {
                return false;
            }

            $seen[$name] = true;
        }

        return true;
    }

    public function filters(): array
    {
        return array_filter($this->validated(), fn ($value, $key) => in_array($key, self::FILTER_KEYS, true) && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? '1');
    }

    public function pageSize(): int
    {
        return (int) ($this->validated('per_page') ?? '15');
    }

    public function sortColumn(): string
    {
        return $this->validated('sort') ?? 'created_at';
    }

    public function sortDirection(): string
    {
        return $this->validated('direction') ?? 'desc';
    }

    private function validateIntegerRange(Validator $validator, mixed $value, string $key): void
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return;
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            $validator->errors()->add($key, 'El valor solicitado está fuera de rango.');
        }
    }

    private function isValidMoney(string $value): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D', $value) === 1;
    }
}
