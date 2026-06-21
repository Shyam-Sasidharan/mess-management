<?php

namespace App\Providers;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\EloquentCustomerRepository;
use Illuminate\Pagination\Paginator;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CustomerRepositoryInterface::class, EloquentCustomerRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        try {
            if (Schema::hasTable('settings')) {
                $timezone = Setting::value('timezone', config('app.timezone'));
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
            }
        } catch (\Throwable) {
            // Database-backed settings become available after installation.
        }
    }
}
