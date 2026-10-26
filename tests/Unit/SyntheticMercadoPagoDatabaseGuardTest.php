<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;

class SyntheticMercadoPagoDatabaseGuardTest extends TestCase
{
    use SyntheticMercadoPagoLink;

    private function mysqlFacts(): array
    {
        return ['environment' => 'testing', 'debug' => false, 'driver' => 'mysql',
            'database' => 'synthetic_'.bin2hex(random_bytes(8)).'_test', 'ordinary_database' => 'ordinary_'.bin2hex(random_bytes(8)),
            'host' => '127.0.0.1', 'port' => '3306', 'effective_driver' => 'mysql',
            'effective_local' => true, 'effective_port' => 3306];
    }

    public function test_sqlite_memory_is_allowed(): void
    {
        $this->assertTrue($this->syntheticDatabaseGuardAllows(['environment' => 'testing', 'debug' => false,
            'driver' => 'sqlite', 'database' => ':memory:', 'effective_driver' => 'sqlite', 'effective_database' => ':memory:']), 'Isolated SQLite must be allowed.');
    }

    public function test_local_mysql_test_database_is_allowed(): void
    {
        $facts = $this->mysqlFacts();
        $facts['effective_database'] = $facts['database'];
        $this->assertTrue($this->syntheticDatabaseGuardAllows($facts), 'Isolated local MySQL must be allowed.');
    }

    /** @dataProvider unsafeNames */
    public function test_unsafe_mysql_database_names_are_rejected(mixed $name): void
    {
        $facts = $this->mysqlFacts();
        $facts['database'] = $name;
        $facts['effective_database'] = $name;
        $this->assertTrue(! $this->syntheticDatabaseGuardAllows($facts), 'Unsafe database names must be rejected.');
    }

    public static function unsafeNames(): array
    {
        return [[null], [''], ['lubricantes'], ['LUBRICANTES'], ['ordinary'], ['safe_TEST'], ['safe_test '], [' safe_test'], ['safe_test_backup'], ['_test'], ['../safe_test'], ['safe-test'], ['safe;DROP_test'], [['safe_test']]];
    }

    /** @dataProvider unsafeConditions */
    public function test_unsafe_environment_or_effective_connection_is_rejected(string $case): void
    {
        $facts = $this->mysqlFacts();
        $facts['effective_database'] = $facts['database'];
        switch ($case) {
            case 'ordinary database': $facts['ordinary_database'] = $facts['database']; break;
            case 'ordinary case alias': $facts['ordinary_database'] = strtoupper($facts['database']); break;
            case 'environment': $facts['environment'] = 'production'; break;
            case 'debug': $facts['debug'] = true; break;
            case 'unknown driver': $facts['driver'] = 'pgsql'; break;
            case 'file sqlite': $facts['driver'] = 'sqlite'; $facts['database'] = 'synthetic.sqlite'; break;
            case 'effective sqlite file': $facts = ['environment' => 'testing', 'debug' => false, 'driver' => 'sqlite', 'database' => ':memory:', 'effective_driver' => 'sqlite', 'effective_database' => 'synthetic.sqlite']; break;
            case 'remote host': $facts['host'] = 'example.invalid'; break;
            case 'url override': $facts['url'] = 'synthetic'; break;
            case 'read override': $facts['read'] = ['host' => 'example.invalid']; break;
            case 'socket override': $facts['unix_socket'] = 'synthetic'; break;
            case 'effective remote': $facts['effective_local'] = false; break;
            case 'effective database mismatch': $facts['effective_database'] = 'different_test'; break;
            case 'effective port mismatch': $facts['effective_port'] = 3307; break;
            case 'effective driver mismatch': $facts['effective_driver'] = 'sqlite'; break;
            case 'ordinary unknown': $facts['ordinary_database'] = null; break;
        }
        $this->assertTrue(! $this->syntheticDatabaseGuardAllows($facts), 'Unsafe isolation conditions must be rejected.');
    }

    public static function unsafeConditions(): array
    {
        $cases = ['ordinary database', 'ordinary case alias', 'environment', 'debug', 'unknown driver', 'file sqlite', 'effective sqlite file', 'remote host', 'url override', 'read override', 'socket override', 'effective remote', 'effective database mismatch', 'effective port mismatch', 'effective driver mismatch', 'ordinary unknown'];
        return array_map(static fn ($case) => [$case], $cases);
    }
}
