<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class AppendOnlyAuditLogBuilder extends Builder
{
    private const WRITE_METHODS = [
        'create', 'forceCreate', 'firstOrCreate', 'updateOrCreate',
        'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing', 'insertOrIgnoreUsing',
        'update', 'updateFrom', 'updateOrInsert', 'upsert',
        'delete', 'forceDelete', 'truncate', 'touch',
        'increment', 'decrement', 'incrementEach', 'decrementEach',
    ];

    public function create(array $attributes = [])
    {
        throw new LogicException('Use AuditService para agregar eventos validados.');
    }

    public function forceCreate(array $attributes)
    {
        throw new LogicException('Use AuditService para agregar eventos validados.');
    }

    public function firstOrCreate(array $attributes = [], array $values = [])
    {
        throw new LogicException('Use AuditService para agregar eventos validados.');
    }

    public function updateOrCreate(array $attributes, array $values = [])
    {
        throw new LogicException('La bitácora de auditoría no admite upsert de modelos.');
    }

    public function update(array $values)
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function delete()
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function forceDelete()
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function insert(array $values)
    {
        throw new LogicException('Use AuditService para agregar eventos validados.');
    }

    public function insertOrIgnore(array $values)
    {
        throw new LogicException('La bitácora de auditoría no admite insertOrIgnore.');
    }

    public function insertGetId(array $values, $sequence = null)
    {
        throw new LogicException('Use AuditService para agregar eventos validados.');
    }

    public function insertUsing(array $columns, $query)
    {
        throw new LogicException('La bitácora de auditoría no admite inserciones desde consultas.');
    }

    public function updateOrInsert(array $attributes, array $values = [])
    {
        throw new LogicException('La bitácora de auditoría no admite updateOrInsert.');
    }

    public function updateFrom(array $values)
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function touch($column = null)
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function truncate()
    {
        throw new LogicException('La bitácora de auditoría es append-only.');
    }

    public function __call($method, $parameters)
    {
        if (in_array($method, self::WRITE_METHODS, true)) {
            throw new LogicException('La bitácora de auditoría es append-only.');
        }

        return parent::__call($method, $parameters);
    }
}
