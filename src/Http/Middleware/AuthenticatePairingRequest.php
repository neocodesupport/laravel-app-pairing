<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Neocode\LaravelAppPairing\Contracts\NonceStore;
use Neocode\LaravelAppPairing\Crypto\BodyHasher;
use Neocode\LaravelAppPairing\Crypto\CanonicalRequest;
use Neocode\LaravelAppPairing\Crypto\Signer;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingAuthenticationFailed;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingKey;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;
use Neocode\LaravelAppPairing\Support\PairingHeaders;
use Neocode\LaravelAppPairing\Support\PairingResponses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a normal, post-pairing signed request: pairing must exist
 * and be ACTIVE, the app_id/pairing_id must match, the timestamp and nonce
 * must be fresh, and the signature must verify against a currently
 * eligible remote public key.
 *
 * On success, the resolved Pairing is available via `$request->attributes->get('pairing')`.
 */
class AuthenticatePairingRequest
{
    public function __construct(
        private readonly Repository $config,
        private readonly NonceStore $nonceStore,
        private readonly Signer $signer,
        private readonly BodyHasher $bodyHasher,
        private readonly PairingActivityLogger $activityLogger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('pairing.https.required') && ! $request->secure()) {
            return PairingResponses::error('https_required', 'HTTPS is required for pairing endpoints.', 400);
        }

        $appId = $request->header(PairingHeaders::APP_ID);
        $pairingId = $request->header(PairingHeaders::PAIRING_ID);
        $timestamp = $request->header(PairingHeaders::TIMESTAMP);
        $nonce = $request->header(PairingHeaders::NONCE);
        $signature = $request->header(PairingHeaders::SIGNATURE);

        if (! is_string($appId) || ! is_string($pairingId) || ! is_string($timestamp) || ! is_string($nonce) || ! is_string($signature)
            || $appId === '' || $pairingId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->fail(null, 'missing_headers', 'One or more required pairing authentication headers are missing.', 401);
        }

        $pairing = Pairing::query()
            ->where('pairing_uuid', $pairingId)
            ->whereHas('remoteApplication', fn (Builder $query) => $query->where('app_id', $appId))
            ->first();

        if ($pairing === null) {
            return $this->fail(null, 'unknown_pairing', 'No matching pairing was found.', 401);
        }

        if ($pairing->status !== PairingStatus::Active) {
            return $this->fail($pairing, 'pairing_not_active', 'The pairing is not active.', 401);
        }

        $ttl = (int) $this->config->get('pairing.signature_ttl', 300);

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $ttl) {
            return $this->fail($pairing, 'timestamp_out_of_window', 'The request timestamp is outside the acceptable window.', 401);
        }

        if (! $this->nonceStore->remember($pairing->pairing_uuid, $nonce, $ttl)) {
            return $this->fail($pairing, 'nonce_reused', 'The request nonce has already been used.', 401);
        }

        $canonical = new CanonicalRequest(
            method: $request->getMethod(),
            path: $request->getPathInfo(),
            timestamp: $timestamp,
            nonce: $nonce,
            bodyHash: $this->bodyHasher->hash((string) $request->getContent()),
            appId: $appId,
            pairingId: $pairingId,
        );

        $graceSeconds = (int) $this->config->get('pairing.key_rotation_grace_period', 3600);
        $canonicalString = $canonical->toString();

        $verified = $pairing->remoteKeysEligibleForVerification($graceSeconds)
            ->get()
            ->contains(fn (PairingKey $key): bool => $this->signer->verify($canonicalString, $signature, $key->public_key));

        if (! $verified) {
            return $this->fail($pairing, 'invalid_signature', 'The request signature could not be verified.', 401);
        }

        $pairing->forceFill(['last_communication_at' => now()])->save();

        $request->attributes->set('pairing', $pairing);

        return $next($request);
    }

    private function fail(?Pairing $pairing, string $code, string $message, int $status): Response
    {
        $this->activityLogger->log('pairing_authentication_failed', [
            'pairing_id' => $pairing?->id,
            'reason' => $code,
        ], $pairing);

        PairingAuthenticationFailed::dispatch($pairing, $code);

        return PairingResponses::error($code, $message, $status);
    }
}
