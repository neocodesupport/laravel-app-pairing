<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingRequestDirection;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingRequested;
use Neocode\LaravelAppPairing\Exceptions\PairingRequestException;
use Neocode\LaravelAppPairing\Models\Application;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingRequest;
use Neocode\LaravelAppPairing\Services\LocalApplicationRegistrar;
use Neocode\LaravelAppPairing\Services\PairingRequestService;

beforeEach(function () {
    $this->service = app(PairingRequestService::class);
});

it('creates a pending pairing, local key, and outgoing request when requesting pairing', function () {
    Http::fake([
        '*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $request = $this->service->requestPairing('https://partner.test');

    expect($request->direction)->toBe(PairingRequestDirection::Outgoing)
        ->and($request->status)->toBe(PairingRequestStatus::Pending)
        ->and($request->pairing)->not->toBeNull()
        ->and($request->pairing->status)->toBe(PairingStatus::Pending)
        ->and($request->pairing->keys()->where('owner', PairingKeyOwner::Local)->count())->toBe(1);

    Http::assertSent(function ($sent) {
        return $sent->url() === 'https://partner.test/api/pairing/v1/pairing-requests'
            && $sent->hasHeader('X-Pairing-Bootstrap-Token', 'test-bootstrap-token');
    });
});

it('records an incoming pairing request', function () {
    $request = $this->service->handleIncomingRequest(incomingRequestPayload());

    expect($request->direction)->toBe(PairingRequestDirection::Incoming)
        ->and($request->status)->toBe(PairingRequestStatus::Pending)
        ->and($request->remote_app_id)->toBe('partner-app-id');
});

it('fires the PairingRequested event for incoming requests', function () {
    Event::fake([PairingRequested::class]);

    $this->service->handleIncomingRequest(incomingRequestPayload());

    Event::assertDispatched(PairingRequested::class);
});

it('is idempotent when the same incoming request_id is submitted twice', function () {
    $payload = incomingRequestPayload();

    $first = $this->service->handleIncomingRequest($payload);
    $second = $this->service->handleIncomingRequest($payload);

    expect(PairingRequest::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

it('rejects a request_id reused with a different app_id', function () {
    $payload = incomingRequestPayload();
    $this->service->handleIncomingRequest($payload);

    $conflicting = array_merge($payload, ['app_id' => 'a-different-app-id']);

    $this->service->handleIncomingRequest($conflicting);
})->throws(PairingRequestException::class);

it('rejects an incoming request when an active pairing already exists with that app', function () {
    $local = app(LocalApplicationRegistrar::class)->resolve();
    $remote = Application::query()->create([
        'app_id' => 'partner-app-id',
        'name' => 'Partner',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
    ]);

    Pairing::query()->create([
        'pairing_uuid' => (string) Str::uuid(),
        'local_application_id' => $local->id,
        'remote_application_id' => $remote->id,
        'status' => PairingStatus::Active,
    ]);

    $this->service->handleIncomingRequest(incomingRequestPayload());
})->throws(PairingRequestException::class);

it('allows a new pairing request after a previous pairing with that app was revoked', function () {
    $local = app(LocalApplicationRegistrar::class)->resolve();
    $remote = Application::query()->create([
        'app_id' => 'partner-app-id',
        'name' => 'Partner',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
    ]);

    Pairing::query()->create([
        'pairing_uuid' => (string) Str::uuid(),
        'local_application_id' => $local->id,
        'remote_application_id' => $remote->id,
        'status' => PairingStatus::Revoked,
    ]);

    $request = $this->service->handleIncomingRequest(incomingRequestPayload());

    expect($request->status)->toBe(PairingRequestStatus::Pending);
});

it('expires stale pending requests and cleans up the not-yet-active pairing for outgoing ones', function () {
    Http::fake(['*' => Http::response([], 202)]);

    $request = $this->service->requestPairing('https://partner.test');
    $request->forceFill(['expires_at' => now()->subDay()])->save();

    $count = $this->service->expireStalePending();

    expect($count)->toBe(1)
        ->and($request->fresh()->status)->toBe(PairingRequestStatus::Expired)
        ->and(Pairing::query()->count())->toBe(0);
});

function incomingRequestPayload(): array
{
    return [
        'request_id' => (string) Str::uuid(),
        'app_id' => 'partner-app-id',
        'app_name' => 'Partner App',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
        'public_key' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        'timestamp' => (string) time(),
    ];
}
