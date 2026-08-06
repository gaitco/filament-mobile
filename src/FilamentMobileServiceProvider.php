<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Illuminate\Support\ServiceProvider;

class FilamentMobileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/filament-mobile.php', 'filament-mobile');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/filament-mobile.php' => config_path('filament-mobile.php'),
        ], 'filament-mobile-config');

        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\DoctorCommand::class]);
        }
    }
}
