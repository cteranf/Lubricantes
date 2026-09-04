<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentPreference;
use Illuminate\Console\Command;

class BackfillLegacyPaymentPreferences extends Command
{
    protected $signature = 'payments:backfill-legacy-preferences {--apply : Persist the unambiguous rows} {--chunk=100}';

    protected $description = 'Safely creates historical preference records from unambiguous legacy order preference IDs.';

    public function handle(): int
    {
        $created = $skipped = $conflicts = 0;
        Order::whereNotNull('payment_id')->orderBy('id')->chunkById((int) $this->option('chunk'), function ($orders) use (&$created, &$skipped, &$conflicts) {
            foreach ($orders as $order) {
                if ($order->current_payment_preference_id || PaymentPreference::where('provider_preference_id', $order->payment_id)->exists()) {
                    $skipped++;

                    continue;
                }
                // A legacy preference ID alone cannot prove the remote reference or ownership.
                // Do not manufacture historical links; report it for operator reconciliation instead.
                if (! is_string($order->payment_id) || trim($order->payment_id) === '') {
                    $conflicts++;

                    continue;
                }
                $conflicts++;
            }
        });
        $this->info(json_encode(['mode' => $this->option('apply') ? 'apply' : 'dry-run', 'created' => $created, 'skipped' => $skipped, 'conflicts' => $conflicts]));

        return self::SUCCESS;
    }
}
