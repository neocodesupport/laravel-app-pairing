<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Crypto\BodyHasher;
use Neocode\LaravelAppPairing\Crypto\CanonicalRequest;
use Neocode\LaravelAppPairing\Crypto\Signer;
use Neocode\LaravelAppPairing\Exceptions\PairingException;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Support\PairingHeaders;

/**
 * A ready-to-use HTTP client bound to one active pairing. Every request is
 * automatically signed with the local Ed25519 key for that pairing; callers
 * never need to build headers, timestamps, nonces, or signatures themselves.
 */
class SignedPairingClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly Signer $signer,
        private readonly BodyHasher $bodyHasher,
        private readonly Repository $config,
        private readonly ApplicationIdentity $identity,
        private readonly Pairing $pairing,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->send('POST', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $path, array $data = []): Response
    {
        return $this->send('PUT', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function patch(string $path, array $data = []): Response
    {
        return $this->send('PATCH', $path, $data);
    }

    public function delete(string $path): Response
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    /**
     * @param  'GET'|'POST'|'PUT'|'PATCH'|'DELETE'  $method
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, array $payload): Response
    {
        if (! $this->pairing->isActive()) {
            throw new PairingException('Cannot send a signed request: the pairing is not active.');
        }

        $localKey = $this->pairing->activeLocalKey();

        if ($localKey === null || $localKey->private_key === null) {
            throw new PairingException('Cannot send a signed request: no active local signing key for this pairing.');
        }

        $baseUrl = rtrim((string) $this->pairing->remoteApplication?->base_url, '/');
        $normalizedPath = '/'.ltrim($path, '/');
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = $this->bodyHasher->hash($body);

        $canonical = new CanonicalRequest(
            method: $method,
            path: $normalizedPath,
            timestamp: $timestamp,
            nonce: $nonce,
            bodyHash: $bodyHash,
            appId: $this->identity->appId(),
            pairingId: $this->pairing->pairing_uuid,
        );

        $signature = $this->signer->sign($canonical->toString(), $localKey->private_key);

        $headers = [
            PairingHeaders::APP_ID => $this->identity->appId(),
            PairingHeaders::PAIRING_ID => $this->pairing->pairing_uuid,
            PairingHeaders::TIMESTAMP => $timestamp,
            PairingHeaders::NONCE => $nonce,
            PairingHeaders::SIGNATURE => $signature,
        ];

        $request = $this->pendingRequest()->withHeaders($headers);

        $response = match ($method) {
            'GET' => $request->get($baseUrl.$normalizedPath, $payload),
            'POST' => $request->post($baseUrl.$normalizedPath, $payload),
            'PUT' => $request->put($baseUrl.$normalizedPath, $payload),
            'PATCH' => $request->patch($baseUrl.$normalizedPath, $payload),
            'DELETE' => $request->delete($baseUrl.$normalizedPath, $payload),
        };

        $this->pairing->forceFill(['last_communication_at' => now()])->save();

        return $response;
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config->get('pairing.http.timeout', 15))
            ->connectTimeout((int) $this->config->get('pairing.http.connect_timeout', 5))
            ->retry(
                (int) $this->config->get('pairing.http.retry_times', 3),
                (int) $this->config->get('pairing.http.retry_delay_ms', 250),
                throw: false,
            );
    }
}
