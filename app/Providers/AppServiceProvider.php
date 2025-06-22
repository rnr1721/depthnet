<?php

namespace App\Providers;

use App\Services\Settings\DbOptionsService;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Contracts\Settings\SettingsServiceInterface;
use App\Contracts\Users\AdminUserServiceInterface;
use App\Contracts\Users\UserExporterInterface;
use App\Contracts\Users\UserServiceInterface;
use App\Services\Settings\SettingsService;
use App\Services\Users\AdminUserService;
use App\Services\Users\Exporters\CsvUserExporter;
use App\Services\Users\UserService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserExporterInterface::class, CsvUserExporter::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);
        $this->app->bind(AdminUserServiceInterface::class, AdminUserService::class);

        $this->app->singleton(OptionsServiceInterface::class, DbOptionsService::class);
        $this->app->bind(SettingsServiceInterface::class, SettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
