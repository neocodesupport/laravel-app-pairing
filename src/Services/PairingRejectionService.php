<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Enums\PairingRequestDirection;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingRejected;
use Neocode\LaravelAppPairing\Exceptions\PairingRequestException;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingResponseNotification;
use Neocode\LaravelAppPairing\Models\PairingRequest;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;

class PairingRejectionService
{
    public function __construct(
        private readonly ApplicationIdentity $identity,
        private readonly PairingActivityLogger $activityLogger,
    ) {}

    /**
     * Reject an incoming pending pairing request. No pairing is created.
     */
    public function reject(PairingRequest $request): PairingRequest
    {
        if ($request->direction !== PairingRequestDirection::Incoming || ! $request->isPending()) {
            throw PairingRequestException::alreadyResolved($request->request_id);
        }

        $request->forceFill([
            'status' => PairingRequestStatus::Rejected,
            'responded_at' => now(),
        ])->save();

        DeliverPairingResponseNotification::dispatch(
            $request->request_id,
            (string) $request->remote_base_url,
            [
                'request_id' => $request->request_id,
                'status' => 'rejected',
                'app_id' => $this->identity->appId(),
                'timestamp' => (string) time(),
            ],
        );

        $this->activityLogger->log('pairing_rejected', [
            'request_id' => $request->request_id,
            'remote_app_id' => $request->remote_app_id,
        ], $request);

        PairingRejected::dispatch($request);

        return $request;
    }

    /**
     * Record a rejection response for an outgoing pairing request that this
     * application originally sent, discarding the not-yet-active pairing
     * scaffolding created when the request was sent.
     *
     * @param  array{request_id: string}  $payload
     */
    public function handleIncomingRejection(array $payload): PairingRequest
    {
        $request = PairingRequest::query()
            ->where('request_id', $payload['request_id'])
            ->where('direction', PairingRequestDirection::Outgoing)
            ->where('status', PairingRequestStatus::Pending)
            ->first();

        if ($request === null) {
            throw PairingRequestException::notFound($payload['request_id']);
        }

        if ($request->pairing?->status === PairingStatus::Pending) {
            $request->pairing->delete();
        }

        $request->forceFill([
            'status' => PairingRequestStatus::Rejected,
            'responded_at' => now(),
        ])->save();

        $this->activityLogger->log('pairing_rejected', [
            'request_id' => $request->request_id,
        ], $request);

        PairingRejected::dispatch($request);

        return $request;
    }
}
