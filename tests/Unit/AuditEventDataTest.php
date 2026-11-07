<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Services\AuditSanitizer;
use App\Support\AuditEventContract;
use App\Support\AuditEventData;
use InvalidArgumentException;
use Tests\TestCase;

class AuditEventDataTest extends TestCase
{
    public function test_only_a_validated_event_representation_can_reach_the_model_insert_method(): void
    {
        try {
            AuditLog::appendEvent([]);
            $this->fail('La inserción debe exigir AuditEventData.');
        } catch (\TypeError) {
            $this->assertTrue(true);
        }

        $event = $this->event();
        $event->assertValid();
        $this->assertSame(AuditEventContract::SYSTEM_COMMAND_COMPLETED, $event->toPersistenceArray()['action']);
    }

    public function test_unknown_action_and_mismatched_category_or_retention_are_rejected(): void
    {
        foreach ([
            ['action' => 'unknown.action', 'category' => 'authentication', 'metadata' => null],
            ['category' => AuditEventContract::CATEGORY_ACCESS],
            ['retentionCategory' => AuditEventContract::RETENTION_ACCESS],
        ] as $override) {
            try {
                $this->event($override);
                $this->fail('La representación incompatible debe rechazarse.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_secret_or_nested_metadata_is_rejected_without_retaining_original_values(): void
    {
        foreach ([
            ['password' => 'synthetic-secret-sentinel'],
            ['token' => 'synthetic-secret-sentinel'],
            ['secret' => 'synthetic-secret-sentinel'],
            ['Authorization' => 'synthetic-secret-sentinel'],
            ['changed_fields' => ['email']],
            ['changed_fields' => str_repeat('x', 161)],
        ] as $metadata) {
            try {
                $this->event(['action' => AuditEventContract::USER_UPDATED, 'category' => AuditEventContract::CATEGORY_USER_SECURITY, 'metadata' => $metadata]);
                $this->fail('La metadata no permitida debe rechazarse.');
            } catch (InvalidArgumentException $exception) {
                $this->assertFalse(str_contains($exception->getMessage(), 'synthetic-secret-sentinel'), 'La excepción no debe revelar metadata original.');
            }
        }
    }

    public function test_the_validated_representation_does_not_retain_mutable_input_arrays(): void
    {
        $metadata = ['command_name' => 'synthetic:initial'];
        $event = $this->event(['metadata' => $metadata]);
        $metadata['command_name'] = 'synthetic:mutated-input';
        $copy = $event->toPersistenceArray();
        $copy['metadata']['command_name'] = 'synthetic:mutated-output';

        $this->assertSame(['command_name' => 'synthetic:initial'], $event->toPersistenceArray()['metadata']);
    }

    public function test_sensitive_user_agent_is_absent_from_the_serialized_event_and_all_persisted_fields(): void
    {
        $sentinel = 'Bearer synthetic-token-secret-password-api_key-authorization-cookies-sentinel';
        $event = $this->event(['userAgent' => $sentinel]);
        $values = $event->toPersistenceArray();
        $jsonFields = json_encode([
            $values['metadata'],
            $values['old_values'],
            $values['new_values'],
        ]);

        $this->assertNull($values['user_agent']);
        $this->assertFalse(str_contains(serialize($event), $sentinel), 'La serialización no debe revelar el encabezado original.');
        $this->assertFalse(str_contains((string) $jsonFields, $sentinel), 'Los campos estructurados no deben contener el encabezado original.');
        $this->assertSame(['command_name' => 'synthetic:fixture'], $values['metadata']);
        $this->assertNull($values['old_values']);
        $this->assertNull($values['new_values']);
    }

    private function event(array $overrides = []): AuditEventData
    {
        $action = $overrides['action'] ?? AuditEventContract::SYSTEM_COMMAND_COMPLETED;
        $category = $overrides['category'] ?? AuditEventContract::CATEGORY_SYSTEM;
        $metadata = array_key_exists('metadata', $overrides) ? $overrides['metadata'] : ['command_name' => 'synthetic:fixture'];

        return AuditEventData::fromInput(
            app(AuditSanitizer::class),
            AuditEventContract::ACTOR_COMMAND,
            null,
            $action,
            $category,
            AuditEventContract::RESULT_SUCCEEDED,
            null,
            null,
            null,
            null,
            null,
            $metadata,
            null,
            null,
            '550e8400-e29b-41d4-a716-446655440000',
            null,
            null,
            'POST',
            '192.0.2.42',
            $overrides['userAgent'] ?? 'SyntheticAuditUnit/1.0',
            $overrides['retentionCategory'] ?? AuditEventContract::RETENTION_ADMINISTRATIVE
        );
    }
}
