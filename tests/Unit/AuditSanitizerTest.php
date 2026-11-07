<?php

namespace Tests\Unit;

use App\Services\AuditSanitizer;
use App\Support\AuditEventContract;
use InvalidArgumentException;
use Tests\TestCase;

class AuditSanitizerTest extends TestCase
{
    public function test_snapshot_and_metadata_allow_only_flat_action_specific_values(): void
    {
        $sanitizer = app(AuditSanitizer::class);

        $this->assertSame(['role' => 'admin', 'is_active' => true], $sanitizer->snapshot(AuditEventContract::USER_CREATED, ['role' => 'admin', 'is_active' => true]));
        $this->assertSame(['role' => 'customer'], $sanitizer->snapshot(AuditEventContract::USER_ROLE_CHANGED, ['role' => 'customer']));
        $this->assertSame(['is_active' => false], $sanitizer->snapshot(AuditEventContract::USER_STATUS_CHANGED, ['is_active' => false]));
        $this->assertSame(['changed_fields' => 'email,phone'], $sanitizer->metadata(AuditEventContract::USER_UPDATED, ['changed_fields' => 'email,phone']));
    }

    public function test_secrets_unknown_keys_objects_and_unbounded_metadata_are_rejected(): void
    {
        $sanitizer = app(AuditSanitizer::class);

        foreach ([
            fn () => $sanitizer->snapshot(AuditEventContract::USER_CREATED, ['password' => 'synthetic']),
            fn () => $sanitizer->metadata(AuditEventContract::USER_UPDATED, ['password' => 'synthetic']),
            fn () => $sanitizer->metadata(AuditEventContract::USER_UPDATED, ['changed_fields' => str_repeat('x', 180)]),
            fn () => $sanitizer->metadata(AuditEventContract::USER_UPDATED, ['changed_fields' => ['email']]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('La metadata inválida debe rechazarse.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_configured_snapshot_and_metadata_size_limits_are_enforced(): void
    {
        $sanitizer = app(AuditSanitizer::class);
        config(['audit.metadata_max_bytes' => 16]);

        try {
            $sanitizer->metadata(AuditEventContract::USER_UPDATED, ['changed_fields' => 'can_deliver']);
            $this->fail('La metadata sobre el límite debe rechazarse.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_ip_is_anonymized_and_raw_user_agent_is_never_persisted(): void
    {
        $sanitizer = app(AuditSanitizer::class);
        $this->assertSame('192.0.2.0', $sanitizer->anonymizeIp('192.0.2.123'));
        $this->assertSame('2001:db8::', $sanitizer->anonymizeIp('2001:db8::1234'));
        foreach ([
            'Mozilla/5.0 Synthetic Browser',
            'Bearer synthetic-token-sentinel',
            'secret password api_key authorization cookies synthetic-sensitive-sentinel',
            str_repeat('x', 512),
            "synthetic-control\x01sentinel",
        ] as $userAgent) {
            $this->assertNull($sanitizer->userAgent($userAgent));
        }
        $this->assertNull($sanitizer->anonymizeIp('not-an-ip'));
    }

    public function test_identifiers_are_strings_and_must_be_safe_bounded_tokens(): void
    {
        $sanitizer = app(AuditSanitizer::class);

        $this->assertSame('12345678901234567890', $sanitizer->safeIdentifier('12345678901234567890', 120));
        $this->expectException(InvalidArgumentException::class);
        $sanitizer->safeIdentifier('private/value', 120);
    }
}
