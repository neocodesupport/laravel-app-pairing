<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Crypto\BodyHasher;
use Neocode\LaravelAppPairing\Crypto\CanonicalRequest;
use Neocode\LaravelAppPairing\Crypto\Signer;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Exceptions\PairingException;
use Neocode\LaravelAppPairing\Models\Pairing;

/**
 * Sends the package's own internal lifecycle notifications (revocation,
 * key rotation) that must still be signed with the most recently known
 * local key for a pairing even after that pairing's status has already
 * flipped away from ACTIVE locally.
 *
 * This is deliberately separate from SignedPairingClient, which refuses to
 * sign anything for a pairing that is not ACTIVE — a safety rail for host
 * application business traffic that this internal notifier intentionally
 * does not apply to itself.
 */
class PairingSignedNotifier
{
    public function __construct(
        private readonly Factory $http,
        private readonly Signer $signer,
        private readonly BodyHasher $bodyHasher,
        private readonly Repository $config,
        private readonly ApplicationIdentity $identity,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(Pairing $pairing, string $path, array $payload, ?string $signingKeyId = null): Response
    {
        $key = $signingKeyId !== null
            ? $pairing->keys()->where('id', $signingKeyId)->first()
            : $pairing->keys()->where('owner', PairingKeyOwner::Local)->latest('activated_at')->first();

        if ($key === null || $key->private_key === null) {
            throw new PairingException("No local key material is available to sign a notification for pairing [{$pairing->pairing_uuid}].");
        }

        $baseUrl = rtrim((string) $pairing->remote_base_url, '/');
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $canonical = new CanonicalRequest(
            method: 'POST',
            path: $path,
            timestamp: $timestamp,
            nonce: $nonce,
            bodyHash: $this->bodyHasher->hash($body),
            appId: $this->identity->appId(),
            pairingId: $pairing->pairing_uuid,
        );

        $signature = $this->signer->sign($canonical->toString(), $key->private_key);

        return $this->http
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                PairingHeaders::APP_ID => $this->identity->appId(),
                PairingHeaders::PAIRING_ID => $pairing->pairing_uuid,
                PairingHeaders::TIMESTAMP => $timestamp,
                PairingHeaders::NONCE => $nonce,
                PairingHeaders::SIGNATURE => $signature,
            ])
            ->timeout((int) $this->config->get('pairing.http.timeout', 15))
            ->connectTimeout((int) $this->config->get('pairing.http.connect_timeout', 5))
            ->retry(
                (int) $this->config->get('pairing.http.retry_times', 3),
                (int) $this->config->get('pairing.http.retry_delay_ms', 250),
                throw: false,
            )
            ->post($baseUrl.$path, $payload);
    }
}
