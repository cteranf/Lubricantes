<?php

namespace App\Providers;

use App\Contracts\ElectronicInvoiceGateway;
use App\Contracts\ElectronicReceiptSummaryTransport;
use App\Services\DirectSunatGateway;
use App\Services\LocalSimulatedElectronicReceiptSummaryTransport;
use App\Services\UnavailableElectronicReceiptSummaryTransport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(ElectronicInvoiceGateway::class, DirectSunatGateway::class);
        $this->app->bind(ElectronicReceiptSummaryTransport::class, app()->environment('testing', 'local')
            ? LocalSimulatedElectronicReceiptSummaryTransport::class
            : UnavailableElectronicReceiptSummaryTransport::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
