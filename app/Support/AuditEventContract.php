<?php

namespace App\Support;

use InvalidArgumentException;

final class AuditEventContract
{
    public const AUTH_LOGIN_SUCCEEDED = 'auth.login.succeeded';

    public const AUTH_LOGIN_FAILED = 'auth.login.failed';

    public const AUTH_LOGIN_DENIED = 'auth.login.denied';

    public const AUTH_LOGOUT = 'auth.logout';

    public const AUTH_SESSION_REVOKED = 'auth.session.revoked';

    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_ROLE_CHANGED = 'user.role.changed';

    public const USER_STATUS_CHANGED = 'user.status.changed';

    public const USER_PASSWORD_CHANGED = 'user.password.changed';

    public const USER_PASSWORD_RESET = 'user.password.reset';

    public const SECURITY_ACCESS_DENIED = 'security.access.denied';

    public const SYSTEM_COMMAND_COMPLETED = 'system.command.completed';

    public const AUDIT_LOGS_VIEWED = 'audit.logs.viewed';

    public const AUDIT_LOG_VIEWED = 'audit.log.viewed';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_COMMAND = 'command';

    public const RESULT_SUCCEEDED = 'succeeded';

    public const RESULT_FAILED = 'failed';

    public const RESULT_DENIED = 'denied';

    public const FAILURE_INVALID_CREDENTIALS = 'invalid_credentials';

    public const FAILURE_ACCOUNT_INACTIVE = 'account_inactive';

    public const FAILURE_PROTECTED_ACCESS_DENIED = 'protected_access_denied';

    public const CATEGORY_AUTHENTICATION = 'authentication';

    public const CATEGORY_ACCESS = 'access';

    public const CATEGORY_USER_SECURITY = 'user_security';

    public const CATEGORY_SYSTEM = 'system';

    public const RETENTION_ACCESS = 'access';

    public const RETENTION_ADMINISTRATIVE = 'administrative';

    public const ACTIONS = [
        self::AUTH_LOGIN_SUCCEEDED,
        self::AUTH_LOGIN_FAILED,
        self::AUTH_LOGIN_DENIED,
        self::AUTH_LOGOUT,
        self::AUTH_SESSION_REVOKED,
        self::USER_CREATED,
        self::USER_UPDATED,
        self::USER_ROLE_CHANGED,
        self::USER_STATUS_CHANGED,
        self::USER_PASSWORD_CHANGED,
        self::USER_PASSWORD_RESET,
        self::SECURITY_ACCESS_DENIED,
        self::SYSTEM_COMMAND_COMPLETED,
        self::AUDIT_LOGS_VIEWED,
        self::AUDIT_LOG_VIEWED,
    ];

    public const ACTOR_TYPES = [self::ACTOR_USER, self::ACTOR_SYSTEM, self::ACTOR_COMMAND];

    public const RESULTS = [self::RESULT_SUCCEEDED, self::RESULT_FAILED, self::RESULT_DENIED];

    public const FAILURE_CODES = [self::FAILURE_INVALID_CREDENTIALS, self::FAILURE_ACCOUNT_INACTIVE, self::FAILURE_PROTECTED_ACCESS_DENIED];

    public const AUDITABLE_TYPES = ['user', 'order', 'product', 'category', 'brand', 'branch', 'warehouse', 'payment_setting', 'payment_receiving_account'];

    public const SPECIALIZED_LOG_TYPES = [
        'contact_inquiry_histories', 'inventory_movements', 'inventory_reservations',
        'order_delivery_histories', 'order_fulfillment_histories', 'order_handling_histories',
        'payment_resolution_histories', 'payment_submission_histories', 'payment_transactions',
        'payment_webhook_events',
    ];

    public static function categoryFor(string $action): string
    {
        return match ($action) {
            self::AUTH_LOGIN_SUCCEEDED, self::AUTH_LOGIN_FAILED, self::AUTH_LOGIN_DENIED,
            self::AUTH_LOGOUT, self::AUTH_SESSION_REVOKED => self::CATEGORY_AUTHENTICATION,
            self::SECURITY_ACCESS_DENIED => self::CATEGORY_ACCESS,
            self::AUDIT_LOGS_VIEWED, self::AUDIT_LOG_VIEWED => self::CATEGORY_ACCESS,
            self::USER_CREATED, self::USER_UPDATED, self::USER_ROLE_CHANGED,
            self::USER_STATUS_CHANGED, self::USER_PASSWORD_CHANGED, self::USER_PASSWORD_RESET => self::CATEGORY_USER_SECURITY,
            self::SYSTEM_COMMAND_COMPLETED => self::CATEGORY_SYSTEM,
            default => throw new InvalidArgumentException('La acción de auditoría no está permitida.'),
        };
    }

    public static function retentionFor(string $action): string
    {
        return in_array($action, [self::AUTH_LOGIN_SUCCEEDED, self::AUTH_LOGIN_FAILED, self::AUTH_LOGIN_DENIED, self::AUTH_LOGOUT, self::AUTH_SESSION_REVOKED, self::SECURITY_ACCESS_DENIED, self::AUDIT_LOGS_VIEWED, self::AUDIT_LOG_VIEWED], true)
            ? self::RETENTION_ACCESS
            : self::RETENTION_ADMINISTRATIVE;
    }

    public static function expectedFailureCode(string $action): ?string
    {
        return match ($action) {
            self::AUTH_LOGIN_FAILED => self::FAILURE_INVALID_CREDENTIALS,
            self::AUTH_LOGIN_DENIED => self::FAILURE_ACCOUNT_INACTIVE,
            self::SECURITY_ACCESS_DENIED => self::FAILURE_PROTECTED_ACCESS_DENIED,
            default => null,
        };
    }

    public static function expectedResult(string $action): string
    {
        return match ($action) {
            self::AUTH_LOGIN_FAILED => self::RESULT_FAILED,
            self::AUTH_LOGIN_DENIED, self::SECURITY_ACCESS_DENIED => self::RESULT_DENIED,
            default => self::RESULT_SUCCEEDED,
        };
    }

    public static function allowedSnapshotKeys(string $action): array
    {
        return match ($action) {
            self::USER_CREATED => ['role', 'is_active'],
            self::USER_ROLE_CHANGED => ['role'],
            self::USER_STATUS_CHANGED => ['is_active'],
            default => [],
        };
    }

    public static function allowedMetadataKeys(string $action): array
    {
        return match ($action) {
            self::USER_UPDATED => ['changed_fields'],
            self::AUTH_SESSION_REVOKED => ['reason', 'revoked_count'],
            self::SYSTEM_COMMAND_COMPLETED => ['command_name'],
            self::AUDIT_LOGS_VIEWED => ['scope', 'page', 'per_page'],
            self::AUDIT_LOG_VIEWED => ['scope', 'audit_log_id'],
            default => [],
        };
    }
}
