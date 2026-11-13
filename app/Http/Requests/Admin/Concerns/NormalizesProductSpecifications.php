<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

trait NormalizesProductSpecifications
{
    protected function normalizeProductSpecifications(): void
    {
        if (! $this->exists('specifications')) {
            return;
        }

        $specifications = $this->input('specifications');
        if (is_string($specifications)) {
            $decoded = json_decode($specifications, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $specifications = $decoded;
            }
        }
        if (! is_array($specifications)) {
            $this->merge(['specifications' => $specifications]);

            return;
        }

        foreach ($specifications as $index => $specification) {
            if (! is_array($specification)) {
                continue;
            }
            if (is_string($specification['name'] ?? null)) {
                $specification['name'] = Str::squish($specification['name']);
            }
            if (is_string($specification['value'] ?? null)) {
                $value = str_replace(["\r\n", "\r"], "\n", trim($specification['value']));
                $specification['value'] = preg_replace('/[\t\f\v ]+/u', ' ', $value) ?? $value;
            }
            $specifications[$index] = $specification;
        }

        $this->merge(['specifications' => $specifications]);
    }

    protected function validateUniqueProductSpecificationNames(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $specifications = $this->input('specifications');
            if (! is_array($specifications)) {
                return;
            }

            $seen = [];
            foreach ($specifications as $index => $specification) {
                $name = is_array($specification) ? ($specification['name'] ?? null) : null;
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                $normalized = Str::lower(Str::squish($name));
                if (isset($seen[$normalized])) {
                    $validator->errors()->add(
                        'specifications.'.$index.'.name',
                        'Cada especificación debe tener un nombre diferente.',
                    );

                    continue;
                }

                $seen[$normalized] = true;
            }
        });
    }
}
