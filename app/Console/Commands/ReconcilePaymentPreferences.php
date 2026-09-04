<?php

namespace App\Console\Commands;

use App\Models\PaymentPreference;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Console\Command;

class ReconcilePaymentPreferences extends Command
{
    protected $signature = 'payments:reconcile-preferences {--apply : Explicitly allow remote provider lookups} {--chunk=100}';

    protected $description = 'Reports creating or orphaned payment preferences; it never creates payments.';

    public function handle(): int
    {
        $query = PaymentPreference::whereIn('state', [PaymentPreference::CREATING, PaymentPreference::ORPHANED]);
        $count = $query->count();
        if (! $this->option('apply')) {
            $this->info(json_encode(['mode' => 'dry-run', 'candidates' => $count, 'changed' => 0]));

            return self::SUCCESS;
        }
        $changed = $failed = 0;
        $query->whereNotNull('provider_preference_id')->chunkById((int) $this->option('chunk'), function ($preferences) use (&$changed, &$failed) {
            foreach ($preferences as $preference) {
                try {
                    $remote = PaymentGatewayFactory::create($preference->gateway)->findPreference($preference->provider_preference_id);
                    if (! hash_equals($preference->external_reference, (string) ($remote['external_reference'] ?? ''))) {
                        $failed++;

                        continue;
                    }
                    $preference->update(['state' => PaymentPreference::ACTIVE, 'init_point' => $remote['init_point'], 'sandbox_init_point' => $remote['sandbox_init_point'], 'activated_at' => now(), 'error_code' => null, 'error_message' => null]);
                    $changed++;
                } catch (\Throwable) {
                    $failed++;
                }
            }
        });
        $this->warn(json_encode(['mode' => 'apply', 'candidates' => $count, 'changed' => $changed, 'failed' => $failed]));

        return self::SUCCESS;
    }
}
