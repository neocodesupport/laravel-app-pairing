<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Crypto\BodyHasher;
use Neocode\LaravelAppPairing\Crypto\CanonicalRequest;
use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Crypto\Signer;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Events\PairingAuthenticationFailed;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Services\PairingRevocationService;
use Neocode\LaravelAppPairing\Support\PairingHeaders;

function testEndpointPath(Pairing $pairing): string
{
    return "/api/pairing/v1/pairings/{$pairing->pairing_uuid}/test";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function buildSignedHeaders(string $remoteAppId, string $remotePrivateKey, string $path, array $body = [], array $overrides = []): array
{
    $bodyJson = json_encode($body);
    $timestamp = $overrides['timestamp'] ?? (string) time();
    $nonce = $overrides['nonce'] ?? bin2hex(random_bytes(16));
    $pairingId = $overrides['pairing_id'];
    $signAppId = $overrides['sign_app_id'] ?? $remoteAppId;

    $canonical = new CanonicalRequest(
        method: 'POST',
        path: $path,
        timestamp: $timestamp,
        nonce: $nonce,
        bodyHash: (new BodyHasher)->hash($bodyJson),
        appId: $signAppId,
        pairingId: $pairingId,
    );

    $signature = $overrides['signature'] ?? (new Signer)->sign($canonical->toString(), $remotePrivateKey);

    return [
        PairingHeaders::APP_ID => $overrides['header_app_id'] ?? $remoteAppId,
        PairingHeaders::PAIRING_ID => $overrides['header_pairing_id'] ?? $pairingId,
        PairingHeaders::TIMESTAMP => $timestamp,
        PairingHeaders::NONCE => $nonce,
        PairingHeaders::SIGNATURE => $signature,
    ];
}

beforeEach(function () {
    $this->pairing = makeActivePairing();

    // makeActivePairing() generates its own remote key pair internally and only
    // stores the public half, so replace it here with a key pair whose private
    // half is known to this test, since it needs to sign as "the remote".
    $this->remoteKeyPair = (new KeyPairGenerator)->generate();
    $this->pairing->activeRemoteKeys()->delete();
    $this->pairing->keys()->create([
        'owner' => PairingKeyOwner::Remote,
        'public_key' => $this->remoteKeyPair->publicKey,
        'status' => PairingKeyStatus::Active,
        'activated_at' => now(),
    ]);

    $this->path = testEndpointPath($this->pairing);
    $this->remoteAppId = $this->pairing->remoteApplication->app_id;
});

it('accepts a validly signed request', function () {
    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
    ]);

    $this->postJson($this->path, [], $headers)->assertOk();
});

it('rejects a request with a tampered signature', function () {
    Event::fake([PairingAuthenticationFailed::class]);

    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
    ]);
    $headers[PairingHeaders::SIGNATURE] = base64_encode(str_repeat('0', 64));

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');

    Event::assertDispatched(PairingAuthenticationFailed::class);
});

it('rejects a request signed with the wrong key', function () {
    $wrongKeyPair = (new KeyPairGenerator)->generate();

    $headers = buildSignedHeaders($this->remoteAppId, $wrongKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
    ]);

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');
});

it('rejects a request with an incorrect app_id header', function () {
    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
        'header_app_id' => 'someone-else',
    ]);

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'unknown_pairing');
});

it('rejects a request with an incorrect pairing_id header', function () {
    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
        'header_pairing_id' => (string) Str::uuid(),
    ]);

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'unknown_pairing');
});

it('rejects a request with a timestamp too far in the past', function () {
    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
        'timestamp' => (string) (time() - 600),
    ]);

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'timestamp_out_of_window');
});

it('rejects a request with a timestamp too far in the future', function () {
    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
        'timestamp' => (string) (time() + 600),
    ]);

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'timestamp_out_of_window');
});

it('rejects a reused nonce', function () {
    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
    ]);

    $this->postJson($this->path, [], $headers)->assertOk();
    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'nonce_reused');
});

it('rejects requests once the pairing has been revoked', function () {
    Bus::fake();
    app(PairingRevocationService::class)->revoke($this->pairing);

    $headers = buildSignedHeaders($this->remoteAppId, $this->remoteKeyPair->privateKey, $this->path, [], [
        'pairing_id' => $this->pairing->pairing_uuid,
    ]);

    $this->postJson($this->path, [], $headers)->assertUnauthorized()
        ->assertJsonPath('error.code', 'pairing_not_active');
});

it('rejects a request missing authentication headers', function () {
    $this->postJson($this->path, [])->assertUnauthorized()
        ->assertJsonPath('error.code', 'missing_headers');
});
