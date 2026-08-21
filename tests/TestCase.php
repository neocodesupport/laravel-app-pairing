<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Neocode\LaravelAppPairing\LaravelAppPairingServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            ActivitylogServiceProvider::class,
            LaravelAppPairingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('pairing.https.required', false);
        $app['config']->set('pairing.bootstrap_token', 'test-bootstrap-token');
        $app['config']->set('pairing.identity.app_id', '01HZZZ0000000000000000LOCA');
        $app['config']->set('pairing.identity.name', 'Local Test App');
        $app['config']->set('pairing.identity.base_url', 'https://local.test');
        $app['config']->set('pairing.identity.environment', 'testing');
        $app['config']->set('pairing.identity.api_version', 'v1');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }
}
