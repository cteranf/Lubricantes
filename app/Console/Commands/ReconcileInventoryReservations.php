<?php

namespace App\Console\Commands;

use App\Models\InventoryReservation;
use App\Models\WarehouseInventory;
use App\Services\InventoryReservationExpirationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileInventoryReservations extends Command
{
    protected $signature = 'inventory:reconcile-reservations
                            {--dry-run : No modifica datos (modo predeterminado)}
                            {--fix : Expira solo reservas vencidas inequívocas}';

    protected $description = 'Compara reservas materializadas con reservas activas y vigentes';

    public function handle(InventoryReservationExpirationService $expiration): int
    {
        $rows = WarehouseInventory::query()->orderBy('warehouse_id')->orderBy('product_id')->get();
        $issues = 0;

        foreach ($rows as $inventory) {
            $valid = DB::table('inventory_reservations as ir')
                ->join('orders as o', 'o.id', '=', 'ir.order_id')
                ->where('ir.warehouse_id', $inventory->warehouse_id)
                ->where('ir.product_id', $inventory->product_id)
                ->where('ir.status', InventoryReservation::ACTIVE)
                ->where(function ($query) {
                    $query->whereIn('o.payment_method', ['contra_entrega', 'pago_en_sede'])
                        ->orWhere(function ($online) {
                            $online->whereNotNull('o.reserved_until')->where('o.reserved_until', '>', now());
                        });
                })->whereNull('o.paid_at')->where('o.payment_status', '!=', 'approved')
                ->whereNotIn('o.status', ['canceled', 'rejected', 'delivered'])
                ->sum('ir.quantity');
            $active = InventoryReservation::where('warehouse_id', $inventory->warehouse_id)->where('product_id', $inventory->product_id)->where('status', InventoryReservation::ACTIVE)->sum('quantity');
            $consumed = InventoryReservation::where('warehouse_id', $inventory->warehouse_id)->where('product_id', $inventory->product_id)->where('status', InventoryReservation::CONSUMED)->sum('quantity');

            if ((int) $inventory->reserved_quantity !== (int) $valid) {
                $issues++;
                $this->warn("warehouse={$inventory->warehouse_id} product={$inventory->product_id} stored={$inventory->reserved_quantity} valid={$valid} active={$active}");
            }
            if ($consumed > 0 && $inventory->reserved_quantity > 0) {
                $this->warn("consumed_retaining warehouse={$inventory->warehouse_id} product={$inventory->product_id} consumed={$consumed}");
                $issues++;
            }
        }

        $expired = InventoryReservation::where('status', InventoryReservation::ACTIVE)
            ->where(function ($query) {
                $query->where('expires_at', '<=', now())->orWhereHas('order', fn ($orders) => $orders->whereNotNull('reserved_until')->where('reserved_until', '<=', now()));
            })->get();
        foreach ($expired as $reservation) {
            $this->line("active_expired reservation={$reservation->id} order={$reservation->order_id} product={$reservation->product_id} quantity={$reservation->quantity}");
        }

        $orphans = InventoryReservation::doesntHave('order')->where('status', InventoryReservation::ACTIVE)->get();
        foreach ($orphans as $reservation) {
            $this->warn("active_without_order reservation={$reservation->id}");
            $issues++;
        }

        $paid = InventoryReservation::where('status', InventoryReservation::ACTIVE)->whereHas('order', fn ($orders) => $orders->where('payment_status', 'approved')->orWhereNotNull('paid_at'))->get();
        foreach ($paid as $reservation) {
            $this->warn("active_paid reservation={$reservation->id} order={$reservation->order_id}");
            $issues++;
        }

        if ($this->option('fix')) {
            $result = $expiration->expireDueReservations(limit: 1000);
            $this->info("Correcciones inequívocas aplicadas: {$result['processed']}");
        } else {
            $this->comment('Modo diagnóstico: no se modificaron datos. Use --fix explícitamente para expirar vencidas inequívocas.');
        }

        $this->info("Inconsistencias detectadas: {$issues}");

        return self::SUCCESS;
    }
}
