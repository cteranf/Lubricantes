<?php

namespace App\Console\Commands;

use App\Services\InventoryReservationExpirationService;
use Illuminate\Console\Command;

class ExpireInventoryReservations extends Command
{
    protected $signature = 'inventory:expire-reservations
                            {--dry-run : Solo informa lo que se liberaria}
                            {--order= : Procesa un pedido especifico}
                            {--limit=100 : Maximo de pedidos por ejecucion}
                            {--batch= : Alias heredado de --limit}';

    protected $description = 'Libera reservas de inventario activas que superaron su vencimiento';

    public function handle(InventoryReservationExpirationService $expiration): int
    {
        $limit = (int) ($this->option('batch') ?: $this->option('limit'));
        $orderId = $this->option('order') !== null ? (int) $this->option('order') : null;
        $preview = $expiration->previewDueReservations($orderId, null, [], $limit);

        if ($this->option('dry-run')) {
            $this->info('Modo dry-run: no se modificaron datos.');
            $this->line('Pedidos detectados: '.$preview->pluck('order_id')->unique()->count());
            foreach ($preview->groupBy('order_id') as $id => $reservations) {
                $order = $reservations->first()->order;
                $classification = $expiration->classify($order);
                $this->line("Pedido {$id}: reason={$classification['reason']}".(! empty($classification['manual_review']) ? ' manual_review=true' : '').' '.json_encode($reservations->map(fn ($reservation) => ['reserva' => $reservation->id, 'producto' => $reservation->product_id, 'cantidad' => (int) $reservation->quantity])->values()->all()));
            }

            return self::SUCCESS;
        }

        $result = $expiration->expireDueReservations($orderId, null, [], $limit);
        $this->info("Reservas vencidas: procesadas={$result['processed']}, omitidas={$result['skipped']}, fallidas={$result['failed']}");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
