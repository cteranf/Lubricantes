<?php

namespace App\Console\Commands;

use App\Models\InventoryReservation;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireInventoryReservations extends Command
{
    protected $signature = 'inventory:expire-reservations {--batch=100}';
    protected $description = 'Libera reservas de inventario activas que superaron su vencimiento';

    public function handle(InventoryService $inventory): int
    {
        $processed = 0; $skipped = 0; $failed = 0;
        $batch = max(1, min(1000, (int) $this->option('batch')));
        InventoryReservation::where('status',InventoryReservation::ACTIVE)->where('expires_at','<=',now())
            ->orderBy('id')->chunkById($batch, function ($reservations) use ($inventory, &$processed, &$skipped, &$failed) {
                foreach ($reservations as $reservation) {
                    try {
                        $inventory->expireOrderReservation($reservation) ? $processed++ : $skipped++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error('No se pudo vencer una reserva de inventario.', ['reservation_id'=>$reservation->id,'message'=>$e->getMessage()]);
                    }
                }
            });
        $this->info("Reservas vencidas: procesadas={$processed}, omitidas={$skipped}, fallidas={$failed}");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
