<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PaymentSubmissionInstallmentsMigrationTest extends TestCase
{
    private string $connectionName = 'payment_installments_migration_isolated';

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.'.$this->connectionName => array_merge(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('payment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('expected_amount', 12, 2);
            $table->timestamps();
            $table->unique('order_id', 'payment_submissions_order_id_unique');
            $table->foreign('order_id', 'payment_submissions_order_id_foreign')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->previousConnection);
        config()->offsetUnset('database.connections.'.$this->connectionName);
        parent::tearDown();
    }

    public function test_upgrade_rollback_and_reapplication_preserve_legacy_rows_without_inventing_amounts(): void
    {
        DB::table('orders')->insert(['id' => 41]);
        DB::table('payment_submissions')->insert(['order_id' => 41, 'expected_amount' => '600.00', 'created_at' => now(), 'updated_at' => now()]);
        $migration = $this->migration();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('payment_submissions', 'amount'));
        $this->assertSame('600.00', $this->decimal(DB::table('payment_submissions')->where('order_id', 41)->value('expected_amount')));
        $this->assertNull(DB::table('payment_submissions')->where('order_id', 41)->value('amount'));
        $this->assertSame(1, DB::table('payment_submissions')->count());

        $migration->down();
        $this->assertFalse(Schema::hasColumn('payment_submissions', 'amount'));
        $this->assertSame(1, DB::table('payment_submissions')->count());
        $this->assertSame('600.00', $this->decimal(DB::table('payment_submissions')->where('order_id', 41)->value('expected_amount')));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('payment_submissions', 'amount'));
        $this->assertNull(DB::table('payment_submissions')->where('order_id', 41)->value('amount'));
        $this->assertSame(1, DB::table('payment_submissions')->count());
    }

    public function test_rollback_refuses_to_restore_unique_order_constraint_when_multiple_rows_exist(): void
    {
        DB::table('orders')->insert(['id' => 55]);
        DB::table('payment_submissions')->insert(['order_id' => 55, 'expected_amount' => '600.00', 'created_at' => now(), 'updated_at' => now()]);
        $migration = $this->migration();
        $migration->up();
        DB::table('payment_submissions')->insert([
            ['order_id' => 55, 'expected_amount' => '600.00', 'amount' => '300.00', 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 55, 'expected_amount' => '600.00', 'amount' => '300.00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        try {
            $migration->down();
            $this->fail('La migración permitió perder varias operaciones al restaurar el índice único antiguo.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot restore one-payment-per-order while orders have multiple payment submissions.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('payment_submissions', 'amount'));
        $this->assertSame(3, DB::table('payment_submissions')->where('order_id', 55)->count());
        $this->assertSame(['300.00', '300.00'], DB::table('payment_submissions')->where('order_id', 55)->whereNotNull('amount')->orderBy('id')->pluck('amount')->map(fn ($value) => $this->decimal($value))->all());
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_18_070000_allow_multiple_payment_submissions_per_order.php');
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
