<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Builds the relative API paths used both when registering the package's
 * own routes and when this application calls the equivalent endpoint on a
 * partner application (which is expected to be running the same package).
 */
class PairingRoutePaths
{
    public function __construct(private readonly Repository $config) {}

    public function base(): string
    {
        $prefix = trim((string) $this->config->get('pairing.routes.prefix', 'api/pairing'), '/');
        $version = trim((string) $this->config->get('pairing.routes.version', 'v1'), '/');

        return "/{$prefix}/{$version}";
    }

    public function pairingRequests(): string
    {
        return $this->base().'/pairing-requests';
    }

    public function pairingRequestResponse(string $requestId): string
    {
        return $this->base()."/pairing-requests/{$requestId}/response";
    }

    public function revoke(string $pairingUuid): string
    {
        return $this->base()."/pairings/{$pairingUuid}/revoke";
    }

    public function rotateKey(string $pairingUuid): string
    {
        return $this->base()."/pairings/{$pairingUuid}/rotate-key";
    }

    public function communicationTest(string $pairingUuid): string
    {
        return $this->base()."/pairings/{$pairingUuid}/test";
    }
}
