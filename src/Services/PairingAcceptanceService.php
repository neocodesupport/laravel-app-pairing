<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Enums\PairingRequestDirection;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingAccepted;
use Neocode\LaravelAppPairing\Exceptions\PairingRequestException;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingResponseNotification;
use Neocode\LaravelAppPairing\Models\Application;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingRequest;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;

class PairingAcceptanceService
{
    public function __construct(
        private readonly LocalApplicationRegistrar $localApplications,
        private readonly KeyPairGenerator $keyPairGenerator,
        private readonly PairingActivityLogger $activityLogger,
    ) {}

    /**
     * Accept an incoming pending pairing request, generating our own key
     * pair, activating the pairing locally, and queuing delivery of our
     * public key back to the original requester.
     */
    public function accept(PairingRequest $request): Pairing
    {
        if ($request->direction !== PairingRequestDirection::Incoming || ! $request->isPending()) {
            throw PairingRequestException::alreadyResolved($request->request_id);
        }

        $local = $this->localApplications->resolve();
        $keyPair = $this->keyPairGenerator->generate();
        $pairingUuid = (string) Str::uuid();

        $pairing = DB::transaction(function () use ($request, $local, $keyPair, $pairingUuid) {
            $remoteApplication = Application::query()->updateOrCreate(
                ['app_id' => $request->remote_app_id],
                [
                    'name' => $request->remote_name,
                    'base_url' => $request->remote_base_url,
                    'environment' => $request->remote_environment,
                    'api_version' => $request->remote_api_version,
                ],
            );

            $pairing = Pairing::query()->create([
                'pairing_uuid' => $pairingUuid,
                'local_application_id' => $local->id,
                'remote_application_id' => $remoteApplication->id,
                'remote_name' => $request->remote_name,
                'remote_base_url' => $request->remote_base_url,
                'status' => PairingStatus::Active,
                'requested_at' => $request->requested_at,
                'accepted_at' => now(),
            ]);

            $pairing->keys()->create([
                'owner' => PairingKeyOwner::Local,
                'public_key' => $keyPair->publicKey,
                'private_key' => $keyPair->privateKey,
                'status' => PairingKeyStatus::Active,
                'activated_at' => now(),
            ]);

            $pairing->keys()->create([
                'owner' => PairingKeyOwner::Remote,
                'public_key' => $request->remote_public_key,
                'status' => PairingKeyStatus::Active,
                'activated_at' => now(),
            ]);

            $request->forceFill([
                'status' => PairingRequestStatus::Accepted,
                'responded_at' => now(),
                'pairing_id' => $pairing->id,
            ])->save();

            return $pairing;
        });

        DeliverPairingResponseNotification::dispatch(
            $request->request_id,
            (string) $request->remote_base_url,
            [
                'request_id' => $request->request_id,
                'status' => 'accepted',
                'pairing_id' => $pairingUuid,
                'app_id' => $local->app_id,
                'app_name' => $local->name,
                'base_url' => $local->base_url,
                'environment' => $local->environment,
                'api_version' => $local->api_version,
                'public_key' => $keyPair->publicKey,
                'timestamp' => (string) time(),
            ],
        );

        $this->activityLogger->log('pairing_accepted', [
            'pairing_id' => $pairing->id,
            'request_id' => $request->request_id,
            'remote_app_id' => $request->remote_app_id,
        ], $pairing);

        PairingAccepted::dispatch($pairing);

        return $pairing;
    }

    /**
     * Record the acceptance response for an outgoing pairing request that
     * this application originally sent, activating the local pairing.
     *
     * @param  array{request_id: string, pairing_id: string, app_id: string, app_name: string, base_url: string, environment: string, api_version: string, public_key: string}  $payload
     */
    public function handleIncomingAcceptance(array $payload): Pairing
    {
        $request = PairingRequest::query()
            ->where('request_id', $payload['request_id'])
            ->where('direction', PairingRequestDirection::Outgoing)
            ->where('status', PairingRequestStatus::Pending)
            ->first();

        if ($request === null) {
            throw PairingRequestException::notFound($payload['request_id']);
        }

        $pairing = $request->pairing;

        if ($pairing === null) {
            throw PairingRequestException::mismatchedResponse();
        }

        $pairing = DB::transaction(function () use ($request, $pairing, $payload) {
            $remoteApplication = Application::query()->updateOrCreate(
                ['app_id' => $payload['app_id']],
                [
                    'name' => $payload['app_name'],
                    'base_url' => $payload['base_url'],
                    'environment' => $payload['environment'],
                    'api_version' => $payload['api_version'],
                ],
            );

            $pairing->forceFill([
                'pairing_uuid' => $payload['pairing_id'],
                'remote_application_id' => $remoteApplication->id,
                'remote_name' => $payload['app_name'],
                'remote_base_url' => $payload['base_url'],
                'status' => PairingStatus::Active,
                'accepted_at' => now(),
            ])->save();

            $pairing->keys()->create([
                'owner' => PairingKeyOwner::Remote,
                'public_key' => $payload['public_key'],
                'status' => PairingKeyStatus::Active,
                'activated_at' => now(),
            ]);

            $request->forceFill([
                'status' => PairingRequestStatus::Accepted,
                'responded_at' => now(),
            ])->save();

            return $pairing;
        });

        $this->activityLogger->log('pairing_accepted', [
            'pairing_id' => $pairing->id,
            'request_id' => $request->request_id,
            'remote_app_id' => $payload['app_id'],
        ], $pairing);

        PairingAccepted::dispatch($pairing);

        return $pairing;
    }
}
