<?php

namespace App\Providers;

use App\Contracts\ElectronicInvoiceGateway;
use App\Services\DirectSunatGateway;
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
