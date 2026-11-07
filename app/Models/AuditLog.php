<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyAuditLogBuilder;
use App\Support\AuditEventData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $guarded = ['*'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        self::creating(fn () => throw new LogicException('Use AuditService para agregar eventos a la bitácora.'));
        self::updating(fn () => throw new LogicException('La bitácora de auditoría es inmutable.'));
        self::deleting(fn () => throw new LogicException('La bitácora de auditoría no se puede eliminar.'));
    }

    public static function appendEvent(AuditEventData $event): self
    {
        $event->assertValid();
        $entry = new self;
        foreach ($event->toPersistenceArray() as $attribute => $value) {
            $entry->setAttribute($attribute, $value);
        }

        // Revalidate immediately before the only internal write path. This bypasses
        // Eloquent callbacks and never exposes an authorized Eloquent builder.
        $event->assertValid();
        $id = $entry->getConnection()
            ->table($entry->getTable())
            ->insertGetId($entry->getAttributes(), $entry->getKeyName());

        $entry->setAttribute($entry->getKeyName(), $id);
        $entry->exists = true;
        $entry->wasRecentlyCreated = true;
        $entry->syncOriginal();

        return $entry;
    }

    public function save(array $options = [])
    {
        throw new LogicException('Use AuditService para agregar eventos validados.');
    }

    public function saveQuietly(array $options = [])
    {
        throw new LogicException('La bitácora no admite escrituras silenciosas.');
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function updateQuietly(array $attributes = [], array $options = [])
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function delete()
    {
        throw new LogicException('La bitácora de auditoría no se puede eliminar.');
    }

    public function deleteQuietly()
    {
        throw new LogicException('La bitácora de auditoría no se puede eliminar.');
    }

    public function forceDelete()
    {
        throw new LogicException('La bitácora de auditoría no se puede eliminar.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function touch($attribute = null)
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function touchQuietly($attribute = null)
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function push()
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    public function pushQuietly()
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('La bitácora de auditoría es inmutable.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('La bitácora de auditoría no se puede eliminar.');
    }

    public function newEloquentBuilder($query): AppendOnlyAuditLogBuilder
    {
        return new AppendOnlyAuditLogBuilder($query);
    }
}
