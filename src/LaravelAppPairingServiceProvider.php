<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Neocode\LaravelAppPairing\Console\Commands\PairingKeyCommand;
use Neocode\LaravelAppPairing\Console\Commands\PrunePairingRequestsCommand;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Contracts\NonceStore;
use Neocode\LaravelAppPairing\Http\Middleware\AuthenticatePairingRequest;
use Neocode\LaravelAppPairing\Http\Middleware\EnsureBootstrapToken;
use Neocode\LaravelAppPairing\Support\CacheNonceStore;
use Neocode\LaravelAppPairing\Support\ConfigApplicationIdentity;

class LaravelAppPairingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pairing.php', 'pairing');

        $this->app->bindIf(ApplicationIdentity::class, ConfigApplicationIdentity::class, shared: true);

        $this->app->bindIf(NonceStore::class, CacheNonceStore::class, shared: true);

        $this->app->singleton(LaravelAppPairing::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/pairing.php');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('pairing.bootstrap', EnsureBootstrapToken::class);
        $router->aliasMiddleware('pairing.auth', AuthenticatePairingRequest::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(PrunePairingRequestsCommand::class)->daily();
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/pairing.php' => config_path('pairing.php'),
        ], ['pairing', 'pairing-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['pairing', 'pairing-migrations']);

        $this->commands([
            PairingKeyCommand::class,
            PrunePairingRequestsCommand::class,
        ]);
    }
}
