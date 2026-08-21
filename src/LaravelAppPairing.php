<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing;

use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Http\SignedPairingClient;
use Neocode\LaravelAppPairing\Http\SignedPairingClientFactory;
use Neocode\LaravelAppPairing\Models\Application;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingKey;
use Neocode\LaravelAppPairing\Models\PairingRequest;
use Neocode\LaravelAppPairing\Services\LocalApplicationRegistrar;
use Neocode\LaravelAppPairing\Services\PairingAcceptanceService;
use Neocode\LaravelAppPairing\Services\PairingCommunicationTestService;
use Neocode\LaravelAppPairing\Services\PairingRejectionService;
use Neocode\LaravelAppPairing\Services\PairingRequestService;
use Neocode\LaravelAppPairing\Services\PairingRevocationService;
use Neocode\LaravelAppPairing\Services\PairingRotationService;

/**
 * The single entry point host applications use to drive pairing. Bound as a
 * singleton and exposed through the `LaravelAppPairing` facade.
 */
class LaravelAppPairing
{
    public function __construct(
        private readonly ApplicationIdentity $identity,
        private readonly LocalApplicationRegistrar $localApplications,
        private readonly PairingRequestService $requests,
        private readonly PairingAcceptanceService $acceptance,
        private readonly PairingRejectionService $rejection,
        private readonly PairingRevocationService $revocation,
        private readonly PairingRotationService $rotation,
        private readonly PairingCommunicationTestService $communicationTest,
        private readonly SignedPairingClientFactory $clientFactory,
    ) {}

    public function identity(): ApplicationIdentity
    {
        return $this->identity;
    }

    public function localApplication(): Application
    {
        return $this->localApplications->resolve();
    }

    public function requestPairing(string $remoteBaseUrl): PairingRequest
    {
        return $this->requests->requestPairing($remoteBaseUrl);
    }

    public function accept(PairingRequest $request): Pairing
    {
        return $this->acceptance->accept($request);
    }

    public function reject(PairingRequest $request): PairingRequest
    {
        return $this->rejection->reject($request);
    }

    public function revoke(Pairing $pairing, ?string $reason = null): Pairing
    {
        return $this->revocation->revoke($pairing, $reason);
    }

    public function rotateKey(Pairing $pairing): PairingKey
    {
        return $this->rotation->rotate($pairing);
    }

    /**
     * @return array<string, mixed>
     */
    public function testCommunication(Pairing $pairing): array
    {
        return $this->communicationTest->test($pairing);
    }

    public function clientFor(Pairing $pairing): SignedPairingClient
    {
        return $this->clientFactory->make($pairing);
    }
}
