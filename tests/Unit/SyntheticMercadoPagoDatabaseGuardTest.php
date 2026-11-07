<?php

namespace Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class SyntheticMercadoPagoDatabaseGuardTest extends TestCase
{
    use SyntheticMercadoPagoLink;

    private function mysqlFacts(): array
    {
        return ['environment' => 'testing', 'debug' => false, 'driver' => 'mysql',
            'database' => 'synthetic_'.bin2hex(random_bytes(8)).'_test', 'ordinary_database' => 'ordinary_'.bin2hex(random_bytes(8)),
            'host' => '127.0.0.1', 'port' => '3306', 'effective_driver' => 'mysql',
            'effective_local' => true, 'effective_port' => 3306, 'external_credentials_absent' => true];
    }

    public function test_sqlite_memory_is_allowed(): void
    {
        $this->assertTrue($this->syntheticDatabaseGuardAllows(['environment' => 'testing', 'debug' => false,
            'driver' => 'sqlite', 'database' => ':memory:', 'effective_driver' => 'sqlite', 'effective_database' => ':memory:', 'external_credentials_absent' => true]), 'Isolated SQLite must be allowed.');
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
            case 'ordinary database': $facts['ordinary_database'] = $facts['database'];
                break;
            case 'ordinary case alias': $facts['ordinary_database'] = strtoupper($facts['database']);
                break;
            case 'environment': $facts['environment'] = 'production';
                break;
            case 'debug': $facts['debug'] = true;
                break;
            case 'unknown driver': $facts['driver'] = 'pgsql';
                break;
            case 'file sqlite': $facts['driver'] = 'sqlite';
                $facts['database'] = 'synthetic.sqlite';
                break;
            case 'effective sqlite file': $facts = ['environment' => 'testing', 'debug' => false, 'driver' => 'sqlite', 'database' => ':memory:', 'effective_driver' => 'sqlite', 'effective_database' => 'synthetic.sqlite'];
                break;
            case 'remote host': $facts['host'] = 'example.invalid';
                break;
            case 'url override': $facts['url'] = 'synthetic';
                break;
            case 'read override': $facts['read'] = ['host' => 'example.invalid'];
                break;
            case 'socket override': $facts['unix_socket'] = 'synthetic';
                break;
            case 'effective remote': $facts['effective_local'] = false;
                break;
            case 'effective database mismatch': $facts['effective_database'] = 'different_test';
                break;
            case 'effective port mismatch': $facts['effective_port'] = 3307;
                break;
            case 'effective driver mismatch': $facts['effective_driver'] = 'sqlite';
                break;
            case 'ordinary unknown': $facts['ordinary_database'] = null;
                break;
            case 'external credentials': $facts['external_credentials_absent'] = false;
                break;
        }
        $this->assertTrue(! $this->syntheticDatabaseGuardAllows($facts), 'Unsafe isolation conditions must be rejected.');
    }

    public static function unsafeConditions(): array
    {
        $cases = ['ordinary database', 'ordinary case alias', 'environment', 'debug', 'unknown driver', 'file sqlite', 'effective sqlite file', 'remote host', 'url override', 'read override', 'socket override', 'effective remote', 'effective database mismatch', 'effective port mismatch', 'effective driver mismatch', 'ordinary unknown', 'external credentials'];

        return array_map(static fn ($case) => [$case], $cases);
    }

    /** @dataProvider mysqlDriverErrorCodes */
    public function test_mysql_connection_error_codes_map_to_safe_categories(int $code, string $expected): void
    {
        $failure = new PDOException('synthetic driver error', $code);
        $failure->errorInfo = ['HY000', $code, 'synthetic driver detail'];

        $this->assertSame($expected, $this->classifyConnectionFailure($failure));
    }

    public static function mysqlDriverErrorCodes(): array
    {
        return [
            [2002, 'mysql_connection_refused'],
            [2003, 'mysql_connection_refused'],
            [1045, 'mysql_access_denied'],
            [1049, 'mysql_database_missing'],
            [1205, 'mysql_connection_unclassified'],
            [987654, 'mysql_connection_unclassified'],
        ];
    }

    public function test_query_exception_previous_is_classified_without_opening_a_connection(): void
    {
        $pdo = new PDOException('synthetic driver error', 1049);
        $pdo->errorInfo = ['HY000', 1049, 'synthetic driver detail'];
        $failure = new QueryException('SELECT 1', [], $pdo);

        $this->assertSame('mysql_database_missing', $this->classifyConnectionFailure($failure));
    }

    public function test_non_pdo_failure_and_missing_error_info_have_safe_categories(): void
    {
        $this->assertSame('non_pdo_connection_failure', $this->classifyConnectionFailure(new RuntimeException('synthetic failure')));
        $this->assertSame('mysql_connection_unclassified', $this->classifyConnectionFailure(new PDOException('synthetic failure')));
    }

    public function test_public_connection_failure_does_not_expose_exception_sentinels(): void
    {
        $sentinel = 'synthetic-sensitive-connection-sentinel';
        $pdo = new PDOException($sentinel, 1045);
        $pdo->errorInfo = ['HY000', 1045, $sentinel];
        $category = $this->classifyConnectionFailure($pdo);
        $publicFailure = $this->safeConnectionFailure($category);

        ob_start();
        echo $publicFailure->getMessage();
        $capturedOutput = ob_get_clean();

        $this->assertSame('mysql_access_denied', $category, 'Solo debe exponerse la categoría allowlisted.');
        $this->assertFalse(str_contains($publicFailure->getMessage(), $sentinel), 'La excepción pública no debe exponer detalles.');
        $this->assertFalse(str_contains(serialize($publicFailure), $sentinel), 'La serialización no debe exponer detalles.');
        $this->assertFalse(str_contains($publicFailure->getTraceAsString(), $sentinel), 'La traza textual no debe exponer detalles.');
        $this->assertFalse(str_contains((string) $capturedOutput, $sentinel), 'La salida capturada no debe exponer detalles.');
    }

    public function test_previous_exception_chain_is_bounded(): void
    {
        $failure = new PDOException('synthetic driver error', 1049);
        $failure->errorInfo = ['HY000', 1049, 'synthetic detail'];
        for ($depth = 0; $depth < 9; $depth++) {
            $failure = new RuntimeException('synthetic wrapper', 0, $failure);
        }

        $this->assertSame('non_pdo_connection_failure', $this->classifyConnectionFailure($failure));
    }

    public function test_read_only_preflight_uses_phpunit_environment_and_leaves_transaction_depth_zero(): void
    {
        $this->assertSyntheticDatabaseIsolation();
        $this->assertTrue($this->mysqlPreflightPdoOpened, 'El preflight debe abrir PDO.');
        if (config('database.default') === 'mysql') {
            $this->assertTrue($this->mysqlPreflightSelectAttempted, 'El preflight MySQL debe consultar DATABASE().');
        }
        $this->assertSame(0, DB::connection()->transactionLevel(), 'El preflight no debe abrir transacciones.');
    }
}
