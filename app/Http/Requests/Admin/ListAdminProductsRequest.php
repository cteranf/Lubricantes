<?php

namespace App\Http\Requests\Admin;

use App\Services\MoneyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListAdminProductsRequest extends FormRequest
{
    private const FILTER_KEYS = ['search', 'sku', 'name', 'category_id', 'brand_id', 'is_active', 'price_min', 'price_max'];

    private const QUERY_KEYS = ['page', 'per_page', 'sort', 'direction', ...self::FILTER_KEYS];

    private const SORT_COLUMNS = ['id', 'name', 'sku', 'price', 'is_active', 'created_at'];

    private bool $rawQueryParameterNamesAllowed = true;

    private array $originalTextFilters = [];

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
        $this->originalTextFilters = [];

        foreach (self::QUERY_KEYS as $key) {
            if (! array_key_exists($key, $query) || ! is_string($query[$key])) {
                continue;
            }

            if (in_array($key, ['search', 'sku', 'name'], true)) {
                $this->originalTextFilters[$key] = $query[$key];
                $query[$key] = trim($query[$key]);
            } elseif ($query[$key] === '' && ! in_array($key, ['page', 'per_page'], true)) {
                $query[$key] = null;
            }
        }

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
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]*\z/D', 'max:19'],
            'brand_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]*\z/D', 'max:19'],
            'is_active' => ['sometimes', 'nullable', 'string', Rule::in(['0', '1'])],
            'price_min' => ['sometimes', 'nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D'],
            'price_max' => ['sometimes', 'nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->rawQueryParameterNamesAllowed) {
                $validator->errors()->add('query', 'Se recibió un parámetro no permitido.');

                return;
            }

            $data = $this->validationData();
            foreach (array_keys($data) as $key) {
                if (! is_string($key) || ! in_array($key, self::QUERY_KEYS, true)) {
                    $validator->errors()->add('query', 'Se recibió un parámetro no permitido.');
                }
            }

            foreach (['search', 'sku', 'name'] as $key) {
                $original = $this->originalTextFilters[$key] ?? null;
                if (is_string($original) && trim($original) === '') {
                    $validator->errors()->add($key, 'El filtro no puede contener únicamente espacios.');
                }
            }

            foreach (['page', 'category_id', 'brand_id'] as $key) {
                $this->validateIntegerRange($validator, $data[$key] ?? null, $key);
            }

            $money = app(MoneyService::class);
            foreach (['price_min', 'price_max'] as $key) {
                $value = $data[$key] ?? null;
                if (is_string($value) && $this->isValidMoney($value)) {
                    $money->toMinor($value);
                }
            }

            if (is_string($data['price_min'] ?? null)
                && is_string($data['price_max'] ?? null)
                && $this->isValidMoney($data['price_min'])
                && $this->isValidMoney($data['price_max'])
                && $money->toMinor($data['price_min']) > $money->toMinor($data['price_max'])) {
                $validator->errors()->add('price_min', 'El precio mínimo no puede superar el máximo.');
            }
        });
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

    public function filters(): array
    {
        return array_filter($this->validated(), fn ($value, $key) => in_array($key, self::FILTER_KEYS, true) && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
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
        $segments = preg_split('/['.preg_quote($separators !== '' ? $separators : '&', '/').']/', $rawQuery);
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
