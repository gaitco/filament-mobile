<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests;

use Gait\FilamentMobile\FilamentMobileServiceProvider;
use Gait\FilamentMobile\Tests\Fixtures\Models\Post;
use Gait\FilamentMobile\Tests\Fixtures\Models\User;
use Gait\FilamentMobile\Tests\Fixtures\Policies\PostPolicy;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SecretResource;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // Filament's own providers are not auto-discovered under
            // Testbench, and `Schema::make()` resolves the `filament`
            // binding, so the host app's Filament stack is registered by
            // hand. No panel is registered — see defineEnvironment().
            \Livewire\LivewireServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            FilamentMobileServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // No Filament panel is registered in the test app — a panel needs the
        // full HTTP/Livewire stack this package deliberately avoids. The
        // explicit resource list is the supported alternative, and exercising
        // it here keeps that path real.
        $app['config']->set('filament-mobile.resources', [
            PostResource::class,
            BannerResource::class,
            SecretResource::class,
        ]);

        // The guard's provider is what `doctor --user=` resolves through, so
        // it has to point at the fixture user rather than Testbench's default
        // App\Models\User, which does not exist here.
        $app['config']->set('auth.providers.users.model', User::class);

        Gate::policy(Post::class, PostPolicy::class);

        $this->defineDatabaseConnection($app);
    }

    /**
     * SQLite in memory by default — fast, and what Testbench gives us.
     *
     * `DB_CONNECTION=mysql` switches the whole suite onto a real MySQL, which
     * CI runs as a second job. That job exists because the pilot found a
     * `LIKE ... ESCAPE` clause that SQLite parsed happily and MySQL rejected
     * with a syntax error on 100% of search requests. A suite that only ever
     * sees one driver cannot catch that class of bug, and this one didn't.
     */
    protected function defineDatabaseConnection($app): void
    {
        if (env('DB_CONNECTION') !== 'mysql') {
            return;
        }

        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'filament_mobile_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }
}
