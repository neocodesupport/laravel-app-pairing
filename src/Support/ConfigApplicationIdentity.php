<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

use Illuminate\Contracts\Config\Repository;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use RuntimeException;

/**
 * Default ApplicationIdentity resolved from config/pairing.php (which itself
 * reads from the environment). Host applications that need a different
 * source of truth (e.g. a database row, a tenant context) should bind their
 * own implementation of ApplicationIdentity instead of relying on this one.
 */
class ConfigApplicationIdentity implements ApplicationIdentity
{
    public function __construct(private readonly Repository $config) {}

    public function appId(): string
    {
        $appId = $this->config->get('pairing.identity.app_id');

        if (! is_string($appId) || $appId === '') {
            throw new RuntimeException(
                'No PAIRING_APP_ID is configured. Run `php artisan pairing:key` or set PAIRING_APP_ID in your .env file.',
            );
        }

        return $appId;
    }

    public function name(): string
    {
        return (string) $this->config->get('pairing.identity.name');
    }

    public function baseUrl(): string
    {
        return (string) $this->config->get('pairing.identity.base_url');
    }

    public function environment(): string
    {
        return (string) $this->config->get('pairing.identity.environment');
    }

    public function apiVersion(): string
    {
        return (string) $this->config->get('pairing.identity.api_version');
    }
}
