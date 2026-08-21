<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingAccepted;
use Neocode\LaravelAppPairing\Events\PairingRejected;
use Neocode\LaravelAppPairing\Exceptions\PairingRequestException;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingResponseNotification;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Services\PairingAcceptanceService;
use Neocode\LaravelAppPairing\Services\PairingRejectionService;
use Neocode\LaravelAppPairing\Services\PairingRequestService;

beforeEach(function () {
    $this->requests = app(PairingRequestService::class);
    $this->acceptance = app(PairingAcceptanceService::class);
    $this->rejection = app(PairingRejectionService::class);
});

it('activates a pairing with both keys when an incoming request is accepted', function () {
    Bus::fake();
    Event::fake([PairingAccepted::class]);

    $incoming = $this->requests->handleIncomingRequest(incomingPayload());

    $pairing = $this->acceptance->accept($incoming);

    expect($pairing->status)->toBe(PairingStatus::Active)
        ->and($pairing->keys()->where('owner', PairingKeyOwner::Local)->where('status', 'active')->count())->toBe(1)
        ->and($pairing->keys()->where('owner', PairingKeyOwner::Remote)->where('status', 'active')->count())->toBe(1)
        ->and($incoming->fresh()->status)->toBe(PairingRequestStatus::Accepted);

    Bus::assertDispatched(DeliverPairingResponseNotification::class);
    Event::assertDispatched(PairingAccepted::class);
});

it('refuses to accept a request that is not pending', function () {
    $incoming = $this->requests->handleIncomingRequest(incomingPayload());
    Bus::fake();
    $this->acceptance->accept($incoming);

    $this->acceptance->accept($incoming->fresh());
})->throws(PairingRequestException::class);

it('activates the requester pairing when it receives a matching acceptance response', function () {
    Http::fake(['*' => Http::response([], 202)]);
    $outgoing = $this->requests->requestPairing('https://partner.test');

    $remoteKeyPair = (new KeyPairGenerator)->generate();
    $pairingUuid = (string) Str::uuid();

    $pairing = $this->acceptance->handleIncomingAcceptance([
        'request_id' => $outgoing->request_id,
        'pairing_id' => $pairingUuid,
        'app_id' => 'partner-app-id',
        'app_name' => 'Partner App',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
        'public_key' => $remoteKeyPair->publicKey,
    ]);

    expect($pairing->status)->toBe(PairingStatus::Active)
        ->and($pairing->pairing_uuid)->toBe($pairingUuid)
        ->and($pairing->keys()->where('owner', PairingKeyOwner::Remote)->count())->toBe(1);
});

it('rejects an acceptance response for an unknown or already-resolved request', function () {
    $this->acceptance->handleIncomingAcceptance([
        'request_id' => 'does-not-exist',
        'pairing_id' => (string) Str::uuid(),
        'app_id' => 'partner-app-id',
        'app_name' => 'Partner App',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
        'public_key' => (new KeyPairGenerator)->generate()->publicKey,
    ]);
})->throws(PairingRequestException::class);

it('rejects an incoming pending request without creating a pairing', function () {
    Bus::fake();
    Event::fake([PairingRejected::class]);

    $incoming = $this->requests->handleIncomingRequest(incomingPayload());

    $rejected = $this->rejection->reject($incoming);

    expect($rejected->status)->toBe(PairingRequestStatus::Rejected)
        ->and($rejected->pairing_id)->toBeNull();

    Bus::assertDispatched(DeliverPairingResponseNotification::class);
    Event::assertDispatched(PairingRejected::class);
});

it('discards the requester scaffolding when an outgoing request is rejected', function () {
    Http::fake(['*' => Http::response([], 202)]);
    $outgoing = $this->requests->requestPairing('https://partner.test');

    expect(Pairing::query()->count())->toBe(1);

    $rejected = $this->rejection->handleIncomingRejection(['request_id' => $outgoing->request_id]);

    expect($rejected->status)->toBe(PairingRequestStatus::Rejected)
        ->and(Pairing::query()->count())->toBe(0);
});

function incomingPayload(): array
{
    return [
        'request_id' => (string) Str::uuid(),
        'app_id' => 'partner-app-id',
        'app_name' => 'Partner App',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
        'public_key' => (new KeyPairGenerator)->generate()->publicKey,
        'timestamp' => (string) time(),
    ];
}
