<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Neocode\LaravelAppPairing\Exceptions\BootstrapAuthenticationException;
use Neocode\LaravelAppPairing\Support\PairingHeaders;

/**
 * Sends the bootstrap-token-authenticated calls used only during the
 * pairing handshake itself (the initial request, and the accept/reject
 * response delivered back to the original requester). Never used once a
 * pairing is active.
 */
class BootstrapPairingClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $baseUrl, string $path, array $payload): Response
    {
        $token = $this->config->get('pairing.bootstrap_token');

        if (! is_string($token) || $token === '') {
            throw BootstrapAuthenticationException::notConfigured();
        }

        return $this->http
            ->acceptJson()
            ->asJson()
            ->withHeaders([PairingHeaders::BOOTSTRAP_TOKEN => $token])
            ->timeout((int) $this->config->get('pairing.http.timeout', 15))
            ->connectTimeout((int) $this->config->get('pairing.http.connect_timeout', 5))
            ->retry(
                (int) $this->config->get('pairing.http.retry_times', 3),
                (int) $this->config->get('pairing.http.retry_delay_ms', 250),
                throw: false,
            )
            ->post(rtrim($baseUrl, '/').'/'.ltrim($path, '/'), $payload);
    }
}
