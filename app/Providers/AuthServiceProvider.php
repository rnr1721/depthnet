<?php

namespace App\Providers;

use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use App\Services\Auth\AuthService;
use App\Contracts\Auth\AuthServiceInterface;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app->bind(PasswordBrokerContract::class, function ($app) {
            return Password::broker('users');
        });

        $this->app->bind(AuthServiceInterface::class, AuthService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('admin', function (User $user) {
            return $user->isAdmin();
        });
    }
}
