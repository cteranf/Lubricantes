<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ElectronicInvoicingMigrationTest extends TestCase
{
    private string $connectionName = 'electronic_invoice_migration_test';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config(['database.connections.'.$this->connectionName => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);
        DB::connection()->statement('PRAGMA foreign_keys = ON');
        $this->assertSame(1, (int) DB::connection()->selectOne('PRAGMA foreign_keys')->foreign_keys);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_isolated_sqlite_install_constraints_rollback_and_reapplication_preserve_order(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Administrador QA']);
        DB::table('orders')->insert(['id' => 41, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_11_19_071000_create_electronic_invoice_foundation_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('electronic_documents', ['order_id', 'draft_reference', 'preparation_key', 'document_type', 'seller_snapshot', 'recipient_snapshot', 'lines_snapshot', 'amounts_snapshot', 'version']));
        $this->assertTrue(Schema::hasColumns('electronic_document_attempts', ['initiated_by', 'idempotency_key', 'payload_hash', 'request_snapshot', 'status']));
        $foreignKeys = DB::select("PRAGMA foreign_key_list('electronic_documents')");
        $this->assertContains('orders', array_map(fn ($key) => $key->table, $foreignKeys));
        $this->assertContains('users', array_map(fn ($key) => $key->table, $foreignKeys));
        $indexes = array_map(fn ($index) => $index->name, DB::select("PRAGMA index_list('electronic_documents')"));
        $this->assertContains('electronic_documents_order_preparation_unique', $indexes);

        $key = (string) Str::uuid();
        $row = [
            'order_id' => 41, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => $key,
            'document_type' => 'boleta', 'status' => 'draft', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => '{}', 'recipient_snapshot' => '{}', 'lines_snapshot' => '{}', 'amounts_snapshot' => '{}',
            'currency' => 'PEN', 'tax_calculation_status' => 'pending_definition', 'version' => 1,
            'prepared_by' => 1, 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('electronic_documents')->insert($row);
        try {
            DB::table('electronic_documents')->insert($row + ['id' => 2]);
            $this->fail('La clave de preparación repetida debe rechazarse.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('electronic_documents')->count());
        }
        try {
            DB::table('electronic_documents')->insert(array_merge($row, ['order_id' => 999, 'preparation_key' => (string) Str::uuid(), 'draft_reference' => (string) Str::uuid()]));
            $this->fail('La FK de pedido inexistente debe rechazarse.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('electronic_documents')->count());
        }
        $migration->down();
        $this->assertFalse(Schema::hasTable('electronic_documents'));
        $this->assertSame(1, DB::table('orders')->where('id', 41)->count());
        $migration->up();
        $this->assertTrue(Schema::hasTable('electronic_documents'));
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_phase_three_migration_rolls_back_safely_and_can_be_reapplied(): void
    {
        $foundation = require database_path('migrations/2026_11_19_071000_create_electronic_invoice_foundation_tables.php');
        $foundation->up();
        DB::table('orders')->insert(['id' => 73, 'created_at' => now(), 'updated_at' => now()]);
        $documentId = DB::table('electronic_documents')->insertGetId([
            'order_id' => 73,
            'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura',
            'status' => 'draft',
            'environment' => 'testing',
            'connection_type' => 'direct_sunat',
            'seller_snapshot' => '{}',
            'recipient_snapshot' => '{}',
            'lines_snapshot' => '{}',
            'amounts_snapshot' => '{}',
            'currency' => 'PEN',
            'tax_calculation_status' => 'pending_definition',
            'version' => 2,
            'prepared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_19_071200_add_direct_sunat_emission_support.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumns('electronic_invoice_settings', ['sunat_username_encrypted', 'certificate_path', 'certificate_expires_at']));
        $this->assertTrue(Schema::hasColumns('electronic_documents', ['series', 'document_number', 'xml_path', 'accepted_at']));
        $this->assertTrue(Schema::hasColumns('electronic_document_attempts', ['ticket', 'safe_observations']));
        $this->assertTrue(Schema::hasTable('electronic_document_series'));

        DB::table('electronic_document_series')->insert([
            'issuer_tax_id' => '20123456789', 'environment' => 'testing', 'document_type' => 'factura',
            'series' => 'F009', 'next_number' => 17, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            DB::table('electronic_document_series')->insert([
                'issuer_tax_id' => '20123456789', 'environment' => 'testing', 'document_type' => 'factura',
                'series' => 'F010', 'next_number' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('La identidad de emisor, ambiente y tipo debe ser única.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('electronic_document_series')->count());
        }

        $migration->down();
        $this->assertFalse(Schema::hasTable('electronic_document_series'));
        $this->assertFalse(Schema::hasColumn('electronic_documents', 'document_number'));
        $this->assertSame(1, DB::table('orders')->where('id', 73)->count());
        $this->assertSame(1, DB::table('electronic_documents')->where('id', $documentId)->where('version', 2)->count());

        $migration->up();
        $this->assertTrue(Schema::hasTable('electronic_document_series'));
        $this->assertSame(1, DB::table('electronic_documents')->where('id', $documentId)->count());
        DB::table('electronic_documents')->where('id', $documentId)->update(['series' => 'F009', 'document_number' => 17]);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
