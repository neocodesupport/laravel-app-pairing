<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Enums\PairingRequestDirection;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingRequested;
use Neocode\LaravelAppPairing\Exceptions\PairingRequestException;
use Neocode\LaravelAppPairing\Http\BootstrapPairingClient;
use Neocode\LaravelAppPairing\Models\Application;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingRequest;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;
use Neocode\LaravelAppPairing\Support\PairingRoutePaths;

class PairingRequestService
{
    public function __construct(
        private readonly LocalApplicationRegistrar $localApplications,
        private readonly KeyPairGenerator $keyPairGenerator,
        private readonly BootstrapPairingClient $bootstrapClient,
        private readonly PairingRoutePaths $paths,
        private readonly PairingActivityLogger $activityLogger,
        private readonly Repository $config,
    ) {}

    /**
     * Initiate a new outgoing pairing request against a remote application.
     */
    public function requestPairing(string $remoteBaseUrl): PairingRequest
    {
        $local = $this->localApplications->resolve();
        $keyPair = $this->keyPairGenerator->generate();
        $requestId = (string) Str::uuid();

        [$pairing, $request] = DB::transaction(function () use ($local, $keyPair, $requestId, $remoteBaseUrl) {
            $pairing = Pairing::query()->create([
                'pairing_uuid' => (string) Str::uuid(),
                'local_application_id' => $local->id,
                'remote_application_id' => null,
                'remote_base_url' => $remoteBaseUrl,
                'status' => PairingStatus::Pending,
                'requested_at' => now(),
            ]);

            $pairing->keys()->create([
                'owner' => PairingKeyOwner::Local,
                'public_key' => $keyPair->publicKey,
                'private_key' => $keyPair->privateKey,
                'status' => PairingKeyStatus::Active,
                'activated_at' => now(),
            ]);

            $request = PairingRequest::query()->create([
                'request_id' => $requestId,
                'direction' => PairingRequestDirection::Outgoing,
                'pairing_id' => $pairing->id,
                'remote_base_url' => $remoteBaseUrl,
                'status' => PairingRequestStatus::Pending,
                'requested_at' => now(),
                'expires_at' => now()->addSeconds((int) $this->config->get('pairing.pairing_request_ttl', 604800)),
            ]);

            return [$pairing, $request];
        });

        $payload = [
            'request_id' => $requestId,
            'app_id' => $local->app_id,
            'app_name' => $local->name,
            'base_url' => $local->base_url,
            'environment' => $local->environment,
            'api_version' => $local->api_version,
            'public_key' => $keyPair->publicKey,
            'timestamp' => (string) time(),
        ];

        $this->bootstrapClient->post($remoteBaseUrl, $this->paths->pairingRequests(), $payload);

        $this->activityLogger->log('pairing_requested', [
            'request_id' => $requestId,
            'pairing_id' => $pairing->id,
            'remote_base_url' => $remoteBaseUrl,
        ], $request);

        return $request->fresh();
    }

    /**
     * Record an incoming pairing request from a remote application. Callers
     * are expected to have already authenticated the bootstrap token and
     * validated the payload schema before calling this method.
     *
     * @param  array{request_id: string, app_id: string, app_name: string, base_url: string, environment: string, api_version: string, public_key: string, timestamp: string}  $payload
     */
    public function handleIncomingRequest(array $payload): PairingRequest
    {
        $existing = PairingRequest::query()->where('request_id', $payload['request_id'])->first();

        if ($existing !== null) {
            if ($existing->remote_app_id !== $payload['app_id']) {
                throw PairingRequestException::alreadyResolved($payload['request_id']);
            }

            return $existing;
        }

        $this->assertNoConflictingPairing($payload['app_id']);

        $request = PairingRequest::query()->create([
            'request_id' => $payload['request_id'],
            'direction' => PairingRequestDirection::Incoming,
            'remote_app_id' => $payload['app_id'],
            'remote_name' => $payload['app_name'],
            'remote_base_url' => $payload['base_url'],
            'remote_environment' => $payload['environment'],
            'remote_api_version' => $payload['api_version'],
            'remote_public_key' => $payload['public_key'],
            'status' => PairingRequestStatus::Pending,
            'requested_at' => now(),
            'expires_at' => now()->addSeconds((int) $this->config->get('pairing.pairing_request_ttl', 604800)),
        ]);

        $this->activityLogger->log('pairing_requested', [
            'request_id' => $request->request_id,
            'remote_app_id' => $payload['app_id'],
            'remote_app_name' => $payload['app_name'],
            'environment' => $payload['environment'],
        ], $request);

        PairingRequested::dispatch($request);

        return $request;
    }

    /**
     * Expire pairing requests that have been pending for longer than the
     * configured TTL, cleaning up any not-yet-active pairing scaffolding
     * created for outgoing requests that were never answered.
     */
    public function expireStalePending(): int
    {
        $stale = PairingRequest::query()
            ->where('status', PairingRequestStatus::Pending)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $request) {
            $request->forceFill([
                'status' => PairingRequestStatus::Expired,
                'responded_at' => now(),
            ])->save();

            if ($request->direction === PairingRequestDirection::Outgoing
                && $request->pairing?->status === PairingStatus::Pending) {
                $request->pairing->delete();
            }

            $this->activityLogger->log('pairing_request_expired', [
                'request_id' => $request->request_id,
            ], $request);
        }

        return $stale->count();
    }

    private function assertNoConflictingPairing(string $remoteAppId): void
    {
        $remoteApplication = Application::query()->where('app_id', $remoteAppId)->first();

        if ($remoteApplication === null) {
            return;
        }

        $conflict = Pairing::query()
            ->where('remote_application_id', $remoteApplication->id)
            ->whereIn('status', [PairingStatus::Pending, PairingStatus::Active])
            ->exists();

        if ($conflict) {
            throw PairingRequestException::conflictingPairingExists();
        }
    }
}
