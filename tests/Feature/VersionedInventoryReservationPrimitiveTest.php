<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientVersionedInventoryStockException;
use App\Exceptions\InventoryReservationConflictException;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentSubmission;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use Tests\TestCase;

class VersionedInventoryReservationPrimitiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_sequence_one_creates_active_sequence_two_without_changing_physical_stock(): void
    {
        $fixture = $this->fixture();
        $historical = $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $before = $this->financialSnapshot($fixture);

        $created = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));

        $this->assertCount(1, $created);
        $current = $created[0]->fresh();
        $this->assertSame(2, $current->reservation_sequence);
        $this->assertSame(InventoryReservation::ACTIVE, $current->status);
        $this->assertSame($fixture['case']->id, $current->resolution_case_id);
        $this->assertSame($fixture['items'][0]->warehouse_id, $current->warehouse_id);
        $this->assertSame($fixture['items'][0]->quantity, $current->quantity);
        $this->assertSame(InventoryReservation::EXPIRED, $historical->fresh()->status);
        $this->assertSame(10, $fixture['inventories'][0]->fresh()->quantity);
        $this->assertSame(2, $fixture['inventories'][0]->fresh()->reserved_quantity);
        $this->assertSame(10, $fixture['products'][0]->fresh()->stock);
        $this->assertFinancialStateUnchanged($before, $fixture);
    }

    public function test_released_sequence_one_creates_active_sequence_two_when_the_caller_has_already_selected_reassignment(): void
    {
        $fixture = $this->fixture();
        $historical = $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::RELEASED);

        $created = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));

        $this->assertSame(2, $created[0]->reservation_sequence);
        $this->assertSame(InventoryReservation::RELEASED, $historical->fresh()->status);
    }

    public function test_creation_versions_each_item_independently_and_uses_only_its_original_warehouse(): void
    {
        $fixture = $this->fixture(2);
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $this->historicalReservation($fixture, $fixture['items'][1], 1, InventoryReservation::EXPIRED);
        $this->historicalReservation($fixture, $fixture['items'][1], 2, InventoryReservation::RELEASED);

        $created = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));

        $byItem = collect($created)->keyBy('order_item_id');
        $this->assertCount(2, $byItem);
        $this->assertSame(2, $byItem[$fixture['items'][0]->id]->reservation_sequence);
        $this->assertSame(3, $byItem[$fixture['items'][1]->id]->reservation_sequence);
        foreach ($fixture['items'] as $index => $item) {
            $this->assertSame($item->warehouse_id, $byItem[$item->id]->warehouse_id);
            $this->assertSame($item->product_id, $byItem[$item->id]->product_id);
            $this->assertSame($item->quantity, $fixture['inventories'][$index]->fresh()->reserved_quantity);
            $this->assertSame(10, $fixture['inventories'][$index]->fresh()->quantity);
            $this->assertSame(10, $fixture['products'][$index]->fresh()->stock);
        }
    }

    public function test_insufficient_second_item_rolls_back_the_first_versioned_reservation(): void
    {
        $fixture = $this->fixture(2);
        foreach ($fixture['items'] as $item) {
            $this->historicalReservation($fixture, $item, 1, InventoryReservation::EXPIRED);
        }
        $fixture['inventories'][1]->update(['quantity' => 1, 'reserved_quantity' => 0]);
        $fixture['products'][1]->forceFill(['stock' => 1])->save();
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La creación permitió una reasignación parcial sin capacidad para el segundo ítem.');
        } catch (InsufficientVersionedInventoryStockException) {
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_inactive_original_warehouse_conflicts_without_mutations(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $fixture['warehouses'][0]->update(['is_active' => false]);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La reasignación aceptó el almacén original inactivo.');
        } catch (InventoryReservationConflictException $exception) {
            $this->assertNotInstanceOf(InsufficientVersionedInventoryStockException::class, $exception);
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_inactive_original_branch_conflicts_without_mutations(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $fixture['warehouses'][0]->branch->update(['is_active' => false]);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La reasignación aceptó una sede original inactiva.');
        } catch (InventoryReservationConflictException $exception) {
            $this->assertNotInstanceOf(InsufficientVersionedInventoryStockException::class, $exception);
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_coherent_reservation_creation_retry_does_not_create_rows_or_increment_reserved_stock(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $first = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));
        $before = $this->operationalSnapshot($fixture);

        $second = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHours(2)
        ));

        $this->assertSame($first[0]->id, $second[0]->id);
        $this->assertSame($before, $this->operationalSnapshot($fixture));
    }

    public function test_creation_retry_accepts_only_fully_consumed_reservations_with_coherent_movements(): void
    {
        $fixture = $this->fixture(2);
        $this->prepareActiveCaseReservations($fixture);
        $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
            $fixture['order'], $fixture['case']
        ));
        $before = $this->operationalSnapshot($fixture);
        $ids = InventoryReservation::where('resolution_case_id', $fixture['case']->id)->orderBy('order_item_id')->pluck('id')->all();

        $retry = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHours(2)
        ));

        $this->assertSame($ids, collect($retry)->pluck('id')->all());
        $this->assertSame($before, $this->operationalSnapshot($fixture));
    }

    public function test_creation_retry_rejects_consumed_reservations_without_complete_consumption_evidence(): void
    {
        foreach (['missing_movement', 'incompatible_movement', 'missing_consumed_at', 'released_at'] as $scenario) {
            $fixture = $this->fixture();
            $this->prepareActiveCaseReservations($fixture);
            $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            ));
            $reservation = InventoryReservation::where('resolution_case_id', $fixture['case']->id)->firstOrFail();
            $movement = InventoryMovement::where('idempotency_key', 'sale-order-item-'.$fixture['items'][0]->id)->firstOrFail();

            match ($scenario) {
                'missing_movement' => DB::table('inventory_movements')->where('id', $movement->id)->delete(),
                'incompatible_movement' => DB::table('inventory_movements')->where('id', $movement->id)->update(['quantity' => (int) $movement->quantity + 1]),
                'missing_consumed_at' => $reservation->update(['consumed_at' => null]),
                'released_at' => $reservation->update(['released_at' => now()]),
            };
            $before = $this->operationalSnapshot($fixture);

            try {
                $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                    $fixture['order'], $fixture['case'], now()->addHours(2)
                ));
                $this->fail("La creación aceptó evidencia consumed incompleta: {$scenario}.");
            } catch (InventoryReservationConflictException) {
                $this->assertSame($before, $this->operationalSnapshot($fixture));
            }
        }
    }

    public function test_creation_retry_rejects_mixed_or_incomplete_case_sets_without_writes(): void
    {
        foreach (['mixed', 'missing'] as $scenario) {
            $fixture = $this->fixture(2);
            $this->prepareActiveCaseReservations($fixture);
            $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            ));
            $reservations = InventoryReservation::where('resolution_case_id', $fixture['case']->id)->orderBy('id')->get();
            if ($scenario === 'mixed') {
                $reservations[1]->update(['status' => InventoryReservation::ACTIVE, 'consumed_at' => null]);
            } else {
                $reservations[1]->delete();
            }
            $before = $this->operationalSnapshot($fixture);

            try {
                $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                    $fixture['order'], $fixture['case'], now()->addHours(2)
                ));
                $this->fail("La creación aceptó un conjunto consumed incompleto: {$scenario}.");
            } catch (InventoryReservationConflictException) {
                $this->assertSame($before, $this->operationalSnapshot($fixture));
            }
        }
    }

    public function test_partial_case_reservations_conflict_without_repairing_them(): void
    {
        $fixture = $this->fixture(2);
        foreach ($fixture['items'] as $item) {
            $this->historicalReservation($fixture, $item, 1, InventoryReservation::EXPIRED);
        }
        $partial = $this->caseReservation($fixture, $fixture['items'][0], 2, InventoryReservation::ACTIVE);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La reasignación reparó una reserva parcial del caso.');
        } catch (InventoryReservationConflictException) {
            $this->assertSame($partial->id, InventoryReservation::findOrFail($partial->id)->id);
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_occupied_versioned_reservation_idempotency_key_conflicts_without_creating_a_partial_row(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $other = $this->fixture();
        $this->historicalReservation($other, $other['items'][0], 1, InventoryReservation::EXPIRED);
        $occupiedKey = 'treasury-resolution-reassign-case-'.$fixture['case']->id.'-item-'.$fixture['items'][0]->id.'-seq-2';
        InventoryReservation::forceCreate([
            'order_id' => $other['order']->id,
            'order_item_id' => $other['items'][0]->id,
            'reservation_sequence' => 2,
            'product_id' => $other['items'][0]->product_id,
            'warehouse_id' => $other['items'][0]->warehouse_id,
            'quantity' => $other['items'][0]->quantity,
            'status' => InventoryReservation::ACTIVE,
            'expires_at' => now()->addHour(),
            'idempotency_key' => $occupiedKey,
            'metadata' => [],
        ]);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La colisión UNIQUE ajena se aceptó como una reserva coherente del caso.');
        } catch (InventoryReservationConflictException $exception) {
            $this->assertNotInstanceOf(InsufficientVersionedInventoryStockException::class, $exception);
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_non_unique_reservation_insert_error_propagates_and_rolls_back_the_savepoint(): void
    {
        $this->assertInducedFailureRollsBack(
            'reservation_insert',
            function (): array {
                $fixture = $this->fixture();
                $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);

                return $fixture;
            },
            fn (array $fixture) => $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            )),
            'El error no-UNIQUE de reserva fue tratado como idempotencia.'
        );
    }

    public function test_consumption_only_mutates_active_reservations_for_the_resolution_case(): void
    {
        $fixture = $this->fixture();
        $historical = $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $created = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));
        $before = $this->financialSnapshot($fixture);

        $result = $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
            $fixture['order'], $fixture['case']
        ));

        $this->assertSame(1, $result['processed']);
        $this->assertSame(InventoryReservation::EXPIRED, $historical->fresh()->status);
        $this->assertSame(InventoryReservation::CONSUMED, $created[0]->fresh()->status);
        $this->assertNotNull($created[0]->fresh()->consumed_at);
        $this->assertSame(8, $fixture['inventories'][0]->fresh()->quantity);
        $this->assertSame(0, $fixture['inventories'][0]->fresh()->reserved_quantity);
        $this->assertSame(8, $fixture['products'][0]->fresh()->stock);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$fixture['items'][0]->id)->count());
        $this->assertFinancialStateUnchanged($before, $fixture);
    }

    public function test_coherent_consumption_retry_does_not_duplicate_discount_or_movement(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));
        $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
            $fixture['order'], $fixture['case']
        ));
        $before = $this->operationalSnapshot($fixture);

        $retry = $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
            $fixture['order'], $fixture['case']
        ));

        $this->assertSame(0, $retry['processed']);
        $this->assertSame(1, $retry['skipped']);
        $this->assertSame($before, $this->operationalSnapshot($fixture));
    }

    public function test_missing_case_reservation_and_preexisting_incompatible_sale_movement_conflict_without_writes(): void
    {
        $fixture = $this->fixture(2);
        foreach ($fixture['items'] as $item) {
            $this->historicalReservation($fixture, $item, 1, InventoryReservation::EXPIRED);
        }
        $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));
        $caseReservations = InventoryReservation::where('resolution_case_id', $fixture['case']->id)->orderBy('id')->get();
        InventoryMovement::create([
            'warehouse_id' => $caseReservations[0]->warehouse_id,
            'product_id' => $caseReservations[0]->product_id,
            'type' => InventoryMovement::SALE,
            'quantity' => 1,
            'quantity_before' => 10,
            'quantity_after' => 9,
            'reason' => 'Incompatible',
            'reference_type' => 'order',
            'reference_id' => (string) $fixture['order']->id,
            'idempotency_key' => 'sale-order-item-'.$fixture['items'][0]->id,
            'metadata' => [],
            'created_at' => now(),
        ]);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            ));
            $this->fail('El consumo aceptó un movimiento de venta previo incompatible.');
        } catch (InventoryReservationConflictException $exception) {
            $this->assertNotInstanceOf(InsufficientVersionedInventoryStockException::class, $exception);
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_consumption_requires_exactly_one_case_reservation_for_every_order_item(): void
    {
        $fixture = $this->fixture(2);
        foreach ($fixture['items'] as $item) {
            $this->historicalReservation($fixture, $item, 1, InventoryReservation::EXPIRED);
        }
        $this->caseReservation($fixture, $fixture['items'][0], 2, InventoryReservation::ACTIVE);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            ));
            $this->fail('El consumo aceptó un caso sin una reserva por cada ítem.');
        } catch (InventoryReservationConflictException) {
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_creation_rejects_existing_case_reservations_with_terminal_or_duplicate_item_state(): void
    {
        foreach ([InventoryReservation::RELEASED, InventoryReservation::EXPIRED, InventoryReservation::CONSUMED] as $status) {
            $fixture = $this->fixture();
            $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
            $caseReservation = $this->caseReservation($fixture, $fixture['items'][0], 2, $status);
            if ($status === InventoryReservation::CONSUMED) {
                $caseReservation->update(['consumed_at' => now()]);
            }
            $before = $this->operationalSnapshot($fixture);

            try {
                $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                    $fixture['order'], $fixture['case'], now()->addHour()
                ));
                $this->fail("La creación aceptó una reserva terminal del caso: {$status}.");
            } catch (InventoryReservationConflictException) {
                $this->assertSame($before, $this->operationalSnapshot($fixture));
            }
        }

        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $this->caseReservation($fixture, $fixture['items'][0], 2, InventoryReservation::ACTIVE);
        try {
            $this->caseReservation($fixture, $fixture['items'][0], 3, InventoryReservation::ACTIVE);
            $before = $this->operationalSnapshot($fixture);
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La creación aceptó dos reservas del mismo caso para un ítem.');
        } catch (QueryException) {
            $this->assertSame(1, InventoryReservation::where('resolution_case_id', $fixture['case']->id)->count());
        } catch (InventoryReservationConflictException) {
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_consumption_rejects_mixed_or_incoherent_case_reservations_without_writes(): void
    {
        foreach (['mixed_terminal', 'wrong_quantity', 'foreign_item'] as $scenario) {
            $fixture = $this->fixture(2);
            $this->prepareActiveCaseReservations($fixture);
            $reservations = InventoryReservation::where('resolution_case_id', $fixture['case']->id)->orderBy('id')->get();

            if ($scenario === 'mixed_terminal') {
                $reservations[1]->update(['status' => InventoryReservation::RELEASED, 'released_at' => now()]);
            } elseif ($scenario === 'wrong_quantity') {
                $reservations[1]->forceFill(['quantity' => (int) $reservations[1]->quantity + 1])->save();
            } else {
                $foreign = $this->fixture()['items'][0];
                $reservations[1]->forceFill([
                    'order_item_id' => $foreign->id,
                    'product_id' => $foreign->product_id,
                    'warehouse_id' => $foreign->warehouse_id,
                ])->save();
            }
            $before = $this->operationalSnapshot($fixture);

            try {
                $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                    $fixture['order'], $fixture['case']
                ));
                $this->fail("El consumo aceptó un conjunto de caso incoherente: {$scenario}.");
            } catch (InventoryReservationConflictException) {
                $this->assertSame($before, $this->operationalSnapshot($fixture));
            }
        }
    }

    public function test_second_inventory_update_failure_rolls_back_the_first_case_reservation_consumption(): void
    {
        $this->assertInducedFailureRollsBack(
            'second_inventory',
            function (): array {
                $fixture = $this->fixture(2);
                $this->prepareActiveCaseReservations($fixture);
                $fixture['inventories'][1]->update(['quantity' => 11]);
                $fixture['products'][1]->forceFill(['stock' => 11])->save();

                return $fixture;
            },
            fn (array $fixture) => $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            )),
            'El fallo inducido no interrumpió la actualización del segundo inventario.'
        );
    }

    public function test_movement_insert_failure_rolls_back_reservations_balances_and_product_stock(): void
    {
        $this->assertInducedFailureRollsBack(
            'movement_insert',
            function (): array {
                $fixture = $this->fixture();
                $this->prepareActiveCaseReservations($fixture);

                return $fixture;
            },
            fn (array $fixture) => $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            )),
            'El fallo inducido no interrumpió la creación del movimiento de venta.'
        );
    }

    public function test_product_stock_sync_failure_rolls_back_all_prior_case_consumption_mutations(): void
    {
        $this->assertInducedFailureRollsBack(
            'product_update',
            function (): array {
                $fixture = $this->fixture();
                $this->prepareActiveCaseReservations($fixture);

                return $fixture;
            },
            fn (array $fixture) => $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            )),
            'El fallo inducido no interrumpió la sincronización del producto.'
        );
    }

    public function test_methods_require_a_caller_owned_outer_transaction(): void
    {
        $fixture = $this->fixture();
        $before = $this->operationalSnapshot($fixture);
        $originalDefault = (string) config('database.default');
        $originalConnection = DB::connection($originalDefault);
        $originalTransactionLevel = $originalConnection->transactionLevel();
        $temporaryConnection = null;

        try {
            if ($originalConnection->getDriverName() === 'mysql') {
                $temporaryConnection = 'mysql_without_outer_tx_'.bin2hex(random_bytes(4));
                config(["database.connections.{$temporaryConnection}" => config('database.connections.mysql')]);
                DB::purge($temporaryConnection);
                $isolated = DB::connection($temporaryConnection);

                $this->assertSame('lubristore_2b4_resolution_certification_test', $isolated->getDatabaseName());
                $this->assertSame(0, $isolated->transactionLevel());
                DB::setDefaultConnection($temporaryConnection);

                $order = (new Order)->setConnection($temporaryConnection);
                $order->setAttribute('id', $fixture['order']->id);
                $case = (new PaymentResolutionCase)->setConnection($temporaryConnection);
                $case->setAttribute('id', $fixture['case']->id);
            } else {
                $this->assertSame('sqlite', $originalConnection->getDriverName());
                $this->assertSame(0, $originalConnection->transactionLevel());
                $order = $fixture['order'];
                $case = $fixture['case'];
            }

            $isolated = DB::connection();
            $isolated->flushQueryLog();
            $isolated->enableQueryLog();

            foreach (['reserve', 'consume'] as $method) {
                $queryCount = count($isolated->getQueryLog());
                try {
                    if ($method === 'reserve') {
                        app(InventoryService::class)->reserveVersionedForResolutionCase($order, $case, now()->addHour());
                    } else {
                        app(InventoryService::class)->consumeVersionedReservationsForResolutionCase($order, $case);
                    }
                    $this->fail("La primitiva {$method} aceptó ejecutarse sin transacción exterior.");
                } catch (\Throwable $exception) {
                    $this->assertSame(LogicException::class, $exception::class);
                    $this->assertSame($queryCount, count($isolated->getQueryLog()));
                }
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            if ($temporaryConnection !== null) {
                DB::purge($temporaryConnection);
                $connections = config('database.connections');
                unset($connections[$temporaryConnection]);
                config(['database.connections' => $connections]);
            }
        }

        $this->assertSame($originalTransactionLevel, $originalConnection->transactionLevel());
        $this->assertSame(1, (int) $originalConnection->selectOne('SELECT 1 AS connection_usable')->connection_usable);
        $this->assertSame($before, $this->operationalSnapshot($fixture));
    }

    public function test_successful_inner_savepoint_is_rolled_back_when_the_outer_transaction_later_fails(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $before = $this->operationalSnapshot($fixture);

        try {
            DB::transaction(function () use ($fixture): void {
                app(InventoryService::class)->reserveVersionedForResolutionCase(
                    $fixture['order'], $fixture['case'], now()->addHour()
                );
                throw new LogicException('outer transaction failure');
            });
            $this->fail('La transacción exterior no propagó el fallo posterior a la primitiva.');
        } catch (LogicException) {
            DB::flushQueryLog();
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_primitives_do_not_reacquire_financial_locks_and_begin_at_order_items(): void
    {
        $source = file_get_contents(app_path('Services/InventoryService.php'));
        $start = strpos($source, 'private function lockResolutionReservationContext');
        $end = strpos($source, 'private function assertVersionedReservationCapacity');
        $context = substr($source, $start, $end - $start);

        $this->assertStringContainsString("OrderItem::where('order_id', \$order->id)->orderBy('id')->lockForUpdate()", $context);
        $this->assertStringContainsString('InventoryReservation::where(function ($query) use ($order, $items)', $context);
        $this->assertStringContainsString("->orderBy('id')->lockForUpdate()", $context);
        $this->assertStringContainsString('$this->balanceLocks->lock($allProductInventoryPairs->concat($requiredPairs))', $context);
        $this->assertStringContainsString('$this->balanceLocks->canonicalPairs($requiredPairs)', $context);
        $this->assertStringContainsString("Product::whereIn('id', \$productIds)->orderBy('id')->lockForUpdate()", $context);
        $this->assertStringNotContainsString('Order::whereKey($order->id)->lockForUpdate()', $context);
        $this->assertStringNotContainsString('PaymentResolutionCase::whereKey($case->id)->lockForUpdate()', $context);
        $this->assertStringNotContainsString('PaymentSubmission::whereKey($case->payment_submission_id)', $context);
    }

    public function test_unrelated_order_case_closed_or_refund_case_conflict_without_mutations(): void
    {
        foreach (['unrelated', 'closed', 'refund'] as $scenario) {
            $fixture = $this->fixture();
            $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
            $case = match ($scenario) {
                'unrelated' => $this->fixture()['case'],
                'closed' => tap($fixture['case'], fn (PaymentResolutionCase $case) => $case->update(['status' => PaymentResolutionCase::REASSIGNED])),
                'refund' => tap($fixture['case'], fn (PaymentResolutionCase $case) => $case->update(['type' => PaymentResolutionCase::REFUND])),
            };
            $before = $this->operationalSnapshot($fixture);
            try {
                $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                    $fixture['order'], $case, now()->addHour()
                ));
                $this->fail('La primitiva aceptó un caso no elegible para reasignación.');
            } catch (InventoryReservationConflictException) {
                $this->assertSame($before, $this->operationalSnapshot($fixture));
            }
        }
    }

    public function test_historical_corruption_gapped_sequences_and_desynchronized_product_are_handled_deterministically(): void
    {
        $fixture = $this->fixture();
        $first = $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $this->historicalReservation($fixture, $fixture['items'][0], 3, InventoryReservation::RELEASED);

        $created = $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));
        $this->assertSame(4, $created[0]->reservation_sequence);

        $fixture['products'][0]->forceFill(['stock' => 99])->save();
        $before = $this->operationalSnapshot($fixture);
        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->consumeVersionedReservationsForResolutionCase(
                $fixture['order'], $fixture['case']
            ));
            $this->fail('El consumo aceptó Product.stock desincronizado.');
        } catch (InventoryReservationConflictException) {
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }

        $fixture['products'][0]->forceFill(['stock' => 10])->save();
        $first->forceFill(['order_id' => $this->fixture()['order']->id])->save();
        $before = $this->operationalSnapshot($fixture);
        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHours(2)
            ));
            $this->fail('La creación aceptó una reserva histórica vinculada a otro pedido.');
        } catch (InventoryReservationConflictException) {
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    public function test_exact_capacity_succeeds_and_one_unit_shortage_conflicts_without_writes(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        $fixture['inventories'][0]->update(['quantity' => 2, 'reserved_quantity' => 0]);
        $fixture['products'][0]->forceFill(['stock' => 2])->save();

        $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));

        $this->assertSame(2, $fixture['inventories'][0]->fresh()->reserved_quantity);

        $short = $this->fixture();
        $this->historicalReservation($short, $short['items'][0], 1, InventoryReservation::EXPIRED);
        $short['inventories'][0]->update(['quantity' => 1, 'reserved_quantity' => 0]);
        $short['products'][0]->forceFill(['stock' => 1])->save();
        $before = $this->operationalSnapshot($short);
        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $short['order'], $short['case'], now()->addHour()
            ));
            $this->fail('La creación aceptó un saldo disponible inferior por una unidad.');
        } catch (InsufficientVersionedInventoryStockException) {
            $this->assertSame($before, $this->operationalSnapshot($short));
        }
    }

    public function test_negative_inventory_balance_is_a_structural_conflict_without_writes(): void
    {
        $fixture = $this->fixture();
        $this->historicalReservation($fixture, $fixture['items'][0], 1, InventoryReservation::EXPIRED);
        if (DB::connection()->getDriverName() === 'mysql') {
            $beforeRejectedMutation = $this->operationalSnapshot($fixture);
            try {
                DB::table('warehouse_inventories')->where('id', $fixture['inventories'][0]->id)->update([
                    'quantity' => -1,
                    'reserved_quantity' => 0,
                ]);
            } catch (QueryException $exception) {
                $this->assertNegativeValueRejected($exception, 'quantity');
                $this->assertSame($beforeRejectedMutation, $this->operationalSnapshot($fixture));
                $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS connection_usable')->connection_usable);
            }
        } else {
            DB::table('warehouse_inventories')->where('id', $fixture['inventories'][0]->id)->update([
                'quantity' => -1,
                'reserved_quantity' => 0,
            ]);
        }
        DB::table('products')->where('id', $fixture['products'][0]->id)->update(['stock' => -1]);
        $before = $this->operationalSnapshot($fixture);

        try {
            $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
                $fixture['order'], $fixture['case'], now()->addHour()
            ));
            $this->fail('La creación aceptó un saldo negativo como falta normal de capacidad.');
        } catch (InventoryReservationConflictException $exception) {
            $this->assertNotInstanceOf(InsufficientVersionedInventoryStockException::class, $exception);
            $this->assertSame($before, $this->operationalSnapshot($fixture));
        }
    }

    private function assertInducedFailureRollsBack(
        string $kind,
        callable $prepareFixture,
        callable $operation,
        string $failureMessage
    ): void {
        $suffixes = [
            'reservation_insert' => 'res_ins',
            'second_inventory' => 'inv_upd',
            'movement_insert' => 'mov_ins',
            'product_update' => 'prd_upd',
        ];
        $this->assertArrayHasKey($kind, $suffixes);

        $trigger = 'vr_'.$suffixes[$kind].'_'.bin2hex(random_bytes(4));
        $driver = DB::connection()->getDriverName();
        $ddlPdo = $driver === 'mysql' ? $this->mysqlTriggerPdo() : null;
        $triggerCreated = false;
        $mysqlTransactionLevel = null;

        try {
            if ($driver === 'mysql') {
                $this->createFailureTrigger($trigger, $kind, null, $ddlPdo);
                $triggerCreated = true;
            }

            $fixture = $prepareFixture();
            $before = $this->operationalSnapshot($fixture);

            if ($driver === 'sqlite') {
                $this->createFailureTrigger($trigger, $kind, $fixture, null);
                $triggerCreated = true;
            }

            try {
                $operation($fixture);
                $this->fail($failureMessage);
            } catch (QueryException $exception) {
                $this->assertInducedFailure($exception, $kind);
                $this->assertSame($before, $this->operationalSnapshot($fixture));
                $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS connection_usable')->connection_usable);
            }
        } finally {
            if ($triggerCreated && $driver === 'mysql') {
                $mysqlTransactionLevel = DB::transactionLevel();
                if ($mysqlTransactionLevel > 0) {
                    DB::rollBack();
                }
                try {
                    $this->dropFailureTrigger($trigger, $ddlPdo);
                } finally {
                    if ($mysqlTransactionLevel > 0) {
                        DB::beginTransaction();
                    }
                }
            } elseif ($triggerCreated) {
                $this->dropFailureTrigger($trigger, null);
            }
        }

        if ($driver === 'mysql') {
            $this->assertSame(1, $mysqlTransactionLevel);
        }
        $this->assertFalse($this->failureTriggerExists($trigger, $ddlPdo));
        $ddlPdo = null;
    }

    private function createFailureTrigger(string $trigger, string $kind, ?array $fixture, ?PDO $mysqlPdo): void
    {
        $this->assertMatchesRegularExpression('/\Avr_(res_ins|inv_upd|mov_ins|prd_upd)_[a-f0-9]{8}\z/', $trigger);
        $messages = [
            'reservation_insert' => 'reservation insert failure',
            'second_inventory' => 'second inventory failure',
            'movement_insert' => 'movement insert failure',
            'product_update' => 'product sync failure',
        ];
        $this->assertArrayHasKey($kind, $messages);
        $message = $messages[$kind];

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertIsArray($fixture);
            $sql = match ($kind) {
                'reservation_insert' => sprintf(
                    "CREATE TRIGGER `%s` BEFORE INSERT ON inventory_reservations WHEN NEW.resolution_case_id = %d BEGIN SELECT RAISE(ABORT, '%s'); END",
                    $trigger,
                    (int) $fixture['case']->id,
                    $message
                ),
                'second_inventory' => sprintf(
                    "CREATE TRIGGER `%s` BEFORE UPDATE ON warehouse_inventories WHEN NEW.id = %d BEGIN SELECT RAISE(ABORT, '%s'); END",
                    $trigger,
                    (int) $fixture['inventories'][1]->id,
                    $message
                ),
                'movement_insert' => sprintf(
                    "CREATE TRIGGER `%s` BEFORE INSERT ON inventory_movements WHEN NEW.idempotency_key = 'sale-order-item-%d' BEGIN SELECT RAISE(ABORT, '%s'); END",
                    $trigger,
                    (int) $fixture['items'][0]->id,
                    $message
                ),
                'product_update' => sprintf(
                    "CREATE TRIGGER `%s` BEFORE UPDATE ON products WHEN NEW.id = %d BEGIN SELECT RAISE(ABORT, '%s'); END",
                    $trigger,
                    (int) $fixture['products'][0]->id,
                    $message
                ),
            };
            DB::unprepared($sql);

            return;
        }

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertNull($fixture);
        $this->assertInstanceOf(PDO::class, $mysqlPdo);
        [$table, $event, $condition] = match ($kind) {
            'reservation_insert' => ['inventory_reservations', 'INSERT', 'NEW.resolution_case_id IS NOT NULL'],
            'second_inventory' => ['warehouse_inventories', 'UPDATE', 'OLD.quantity = 11 AND NEW.quantity < OLD.quantity'],
            'movement_insert' => ['inventory_movements', 'INSERT', "NEW.type = 'sale' AND NEW.reference_type = 'order'"],
            'product_update' => ['products', 'UPDATE', 'OLD.stock = 10 AND NEW.stock < OLD.stock'],
        };
        $mysqlPdo->exec("CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
    }

    private function assertInducedFailure(QueryException $exception, string $kind): void
    {
        $messages = [
            'reservation_insert' => 'reservation insert failure',
            'second_inventory' => 'second inventory failure',
            'movement_insert' => 'movement insert failure',
            'product_update' => 'product sync failure',
        ];
        [$sqlState, $driverCode, $diagnostic] = $this->queryErrorInfo($exception);
        $this->assertArrayHasKey($kind, $messages);
        $this->assertStringContainsStringIgnoringCase($messages[$kind], $diagnostic);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame(19, $driverCode);
            $this->assertStringNotContainsStringIgnoringCase('unique', $diagnostic);

            return;
        }

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('45000', $sqlState);
        $this->assertSame(1644, $driverCode);
    }

    private function dropFailureTrigger(string $trigger, ?PDO $mysqlPdo): void
    {
        $this->assertMatchesRegularExpression('/\Avr_(res_ins|inv_upd|mov_ins|prd_upd)_[a-f0-9]{8}\z/', $trigger);
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");

            return;
        }

        $this->assertInstanceOf(PDO::class, $mysqlPdo);
        $mysqlPdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
    }

    private function failureTriggerExists(string $trigger, ?PDO $mysqlPdo): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (int) DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $trigger)
                ->count() > 0;
        }

        $this->assertInstanceOf(PDO::class, $mysqlPdo);
        $statement = $mysqlPdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
        $statement->execute([$trigger]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function assertNegativeValueRejected(QueryException $exception, string $column): void
    {
        [$sqlState, $driverCode, $diagnostic] = $this->queryErrorInfo($exception);

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('22003', $sqlState);
        $this->assertSame(1264, $driverCode);
        $this->assertStringContainsStringIgnoringCase('out of range value', $diagnostic);
        $this->assertStringContainsStringIgnoringCase("column '{$column}'", $diagnostic);
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function queryErrorInfo(QueryException $exception): array
    {
        $this->assertIsArray($exception->errorInfo);
        $this->assertCount(3, $exception->errorInfo);

        return [
            (string) $exception->errorInfo[0],
            (int) $exception->errorInfo[1],
            (string) $exception->errorInfo[2],
        ];
    }

    private function mysqlTriggerPdo(): PDO
    {
        $config = config('database.connections.mysql');
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset'] ?? 'utf8mb4'
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $this->assertSame('lubristore_2b4_resolution_certification_test', $pdo->query('SELECT DATABASE()')->fetchColumn());

        return $pdo;
    }

    /** @return array{order: Order, case: PaymentResolutionCase, submission: PaymentSubmission, items: array<int, OrderItem>, inventories: array<int, WarehouseInventory>, products: array<int, Product>, warehouses: array<int, Warehouse>} */
    private function fixture(int $itemCount = 1): array
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $branch = Branch::create(['code' => 'BR-VR-'.Str::random(8), 'name' => 'Sede versionada', 'address' => 'Dirección', 'is_active' => true]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => (string) (25 * $itemCount),
            'shipping_info' => ['address' => 'Prueba'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $items = [];
        $inventories = [];
        $products = [];
        $warehouses = [];
        for ($index = 0; $index < $itemCount; $index++) {
            $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-VR-'.Str::random(8), 'name' => 'Almacén '.$index, 'is_active' => true]);
            $product = Product::create(['name' => 'Producto versionado '.Str::random(8), 'slug' => 'producto-versionado-'.Str::uuid(), 'sku' => 'VR-'.Str::upper(Str::random(8)), 'price' => '25.00', 'is_active' => true]);
            $product->forceFill(['stock' => 10])->save();
            $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 10, 'reserved_quantity' => 0]);
            $items[] = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2, 'price' => '25.00', 'subtotal' => '25.00']);
            $warehouses[] = $warehouse;
            $products[] = $product;
            $inventories[] = $inventory;
        }
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
            'expected_amount' => (string) (25 * $itemCount),
            'currency' => 'PEN',
            'operation_number' => 'VR'.Str::upper(Str::random(10)),
            'normalized_operation_number' => Str::upper(Str::random(20)),
            'duplicate_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'versioned-submission-'.Str::uuid(),
        ]);
        $case = PaymentResolutionCase::forceCreate([
            'payment_submission_id' => $submission->id,
            'status' => PaymentResolutionCase::OPEN,
            'amount' => (string) (25 * $itemCount),
            'currency' => 'PEN',
            'requested_at' => now(),
            'idempotency_key' => 'versioned-case-'.Str::uuid(),
        ]);

        return compact('order', 'case', 'submission', 'items', 'inventories', 'products', 'warehouses');
    }

    /** @param array{order: Order, case: PaymentResolutionCase} $fixture */
    private function historicalReservation(array $fixture, OrderItem $item, int $sequence, string $status): InventoryReservation
    {
        return InventoryReservation::forceCreate([
            'order_id' => $fixture['order']->id,
            'order_item_id' => $item->id,
            'reservation_sequence' => $sequence,
            'product_id' => $item->product_id,
            'warehouse_id' => $item->warehouse_id,
            'quantity' => $item->quantity,
            'status' => $status,
            'expires_at' => now()->subHour(),
            'released_at' => $status === InventoryReservation::RELEASED ? now()->subHour() : null,
            'idempotency_key' => 'historical-'.$item->id.'-'.$sequence.'-'.Str::uuid(),
            'metadata' => [],
        ]);
    }

    /** @param array{order: Order, case: PaymentResolutionCase} $fixture */
    private function caseReservation(array $fixture, OrderItem $item, int $sequence, string $status): InventoryReservation
    {
        return InventoryReservation::forceCreate([
            'order_id' => $fixture['order']->id,
            'order_item_id' => $item->id,
            'reservation_sequence' => $sequence,
            'resolution_case_id' => $fixture['case']->id,
            'product_id' => $item->product_id,
            'warehouse_id' => $item->warehouse_id,
            'quantity' => $item->quantity,
            'status' => $status,
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'treasury-resolution-reassign-case-'.$fixture['case']->id.'-item-'.$item->id.'-seq-'.$sequence,
            'metadata' => [],
        ]);
    }

    /** @param array{order: Order, case: PaymentResolutionCase, items: array<int, OrderItem>} $fixture */
    private function prepareActiveCaseReservations(array $fixture): void
    {
        foreach ($fixture['items'] as $item) {
            $this->historicalReservation($fixture, $item, 1, InventoryReservation::EXPIRED);
        }
        $this->withinTransaction(fn () => app(InventoryService::class)->reserveVersionedForResolutionCase(
            $fixture['order'], $fixture['case'], now()->addHour()
        ));
    }

    private function withinTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    private function financialSnapshot(array $fixture): array
    {
        return [
            'order' => $fixture['order']->fresh()->getAttributes(),
            'items' => OrderItem::where('order_id', $fixture['order']->id)->orderBy('id')->get()->map(fn (OrderItem $item) => $item->getAttributes())->all(),
            'submission' => $fixture['submission']->fresh()->getAttributes(),
            'case' => $fixture['case']->fresh()->getAttributes(),
            'transactions' => DB::table('payment_transactions')->where('order_id', $fixture['order']->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'submission_histories' => DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'case_histories' => DB::table('payment_resolution_histories')->where('payment_resolution_case_id', $fixture['case']->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function assertFinancialStateUnchanged(array $before, array $fixture): void
    {
        $this->assertSame($before, $this->financialSnapshot($fixture));
    }

    private function operationalSnapshot(array $fixture): array
    {
        return [
            'reservations' => InventoryReservation::where('order_id', $fixture['order']->id)->orderBy('id')->get()->map(fn (InventoryReservation $reservation) => $reservation->getAttributes())->all(),
            'inventories' => collect($fixture['inventories'])->map(fn (WarehouseInventory $inventory) => $inventory->fresh()->only(['id', 'quantity', 'reserved_quantity']))->all(),
            'products' => collect($fixture['products'])->map(fn (Product $product) => $product->fresh()->only(['id', 'stock']))->all(),
            'movements' => InventoryMovement::where('reference_id', (string) $fixture['order']->id)->orderBy('id')->get()->map(fn (InventoryMovement $movement) => $movement->getAttributes())->all(),
            'financial' => $this->financialSnapshot($fixture),
        ];
    }
}
