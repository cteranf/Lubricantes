<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentLockSqlObserver;

class PaymentLockSqlObserverTest extends TestCase
{
    /** @dataProvider statements */
    public function test_only_structural_tables_in_update_lock_queries_are_recorded(string $sql, string $driver, bool $intent, array $expected): void
    {
        $this->assertTrue(PaymentLockSqlObserver::tables($sql, $driver, $intent) === $expected, 'Observer must record only relevant locked tables in structural order.');
    }

    public static function statements(): array
    {
        return [
            'sqlite quoted with builder intent' => ['SELECT * FROM "orders" WHERE "id" = ?', 'sqlite', true, ['orders']],
            'mysql backticks' => ['SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE', 'mysql', false, ['orders']],
            'unquoted mixed whitespace' => ["SELECT  * \n FROM   ORDERS \t FOR  UPDATE", 'mysql', false, ['orders']],
            'qualified double quotes' => ['SELECT * FROM "synthetic"."orders" FOR UPDATE', 'mysql', false, ['orders']],
            'qualified mysql' => ['SELECT * FROM `synthetic`.`payment_preferences` FOR UPDATE', 'mysql', false, ['payment_preferences']],
            'qualified bare' => ['SELECT * FROM synthetic.orders FOR UPDATE', 'mysql', false, ['orders']],
            'bracketed identifiers' => ['SELECT * FROM [synthetic].[orders] FOR UPDATE', 'mysql', false, ['orders']],
            'alias and join' => ['SELECT o.id FROM orders AS o JOIN payment_preferences AS p ON p.order_id=o.id FOR UPDATE', 'mysql', false, ['orders', 'payment_preferences']],
            'literal does not count' => ["SELECT 'FROM orders JOIN payment_preferences' FROM users FOR UPDATE", 'mysql', false, []],
            'escaped literal does not count' => ["SELECT 'x'' FROM orders JOIN payment_preferences' FROM users FOR UPDATE", 'mysql', false, []],
            'comment does not count' => ['SELECT * FROM users /* FROM orders FOR UPDATE */', 'mysql', false, []],
            'alias does not count' => ['SELECT * FROM users AS orders FOR UPDATE', 'mysql', false, []],
            'quoted keyword does not count' => ['SELECT "from", "orders" FROM users FOR UPDATE', 'mysql', false, []],
            'mysql no lock' => ['SELECT * FROM orders', 'mysql', true, []],
            'literal lock does not count' => ["SELECT 'FOR UPDATE' FROM orders", 'mysql', false, []],
            'sqlite no builder intent' => ['SELECT * FROM "orders"', 'sqlite', false, []],
            'nested lock does not count for outer table' => ['SELECT (SELECT id FROM users FOR UPDATE) FROM orders', 'mysql', false, []],
            'unknown driver' => ['SELECT * FROM orders FOR UPDATE', 'pgsql', true, []],
        ];
    }

    public function test_sqlite_lock_intent_comes_only_from_the_executing_builder(): void
    {
        $builder = new Builder(new Connection(null));
        $this->assertFalse(PaymentLockSqlObserver::builderRequestsUpdateLock([['object' => $builder, 'function' => 'runSelect']]));
        $builder->lockForUpdate();
        $this->assertTrue(PaymentLockSqlObserver::builderRequestsUpdateLock([['object' => $builder, 'function' => 'runSelect']]));
        $this->assertFalse(PaymentLockSqlObserver::builderRequestsUpdateLock([['object' => $builder, 'function' => 'get']]));
        $builder->sharedLock();
        $this->assertFalse(PaymentLockSqlObserver::builderRequestsUpdateLock([['object' => $builder, 'function' => 'runSelect']]));
    }
}
