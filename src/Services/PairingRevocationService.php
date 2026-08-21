<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingRevoked;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingRevocationNotification;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;

class PairingRevocationService
{
    public function __construct(private readonly PairingActivityLogger $activityLogger) {}

    /**
     * Revoke a pairing immediately and locally, regardless of whether the
     * remote application is reachable. The remote notification is queued
     * and retried independently; local revocation never waits on it.
     *
     * Idempotent: revoking an already-revoked pairing is a no-op.
     */
    public function revoke(Pairing $pairing, ?string $reason = null): Pairing
    {
        if (! $this->markRevoked($pairing, $reason)) {
            return $pairing;
        }

        DeliverPairingRevocationNotification::dispatch($pairing->id);

        return $pairing;
    }

    /**
     * Handle an incoming revocation notice from the remote side of a
     * pairing. Verified by the pairing.auth middleware before this runs.
     * The side that revoked first already knows it revoked, so this does
     * not queue a notification back — that would just bounce forever.
     */
    public function handleIncomingRevocation(Pairing $pairing): Pairing
    {
        $this->markRevoked($pairing, 'revoked_by_remote');

        return $pairing;
    }

    private function markRevoked(Pairing $pairing, ?string $reason): bool
    {
        if ($pairing->status === PairingStatus::Revoked) {
            return false;
        }

        $pairing->forceFill([
            'status' => PairingStatus::Revoked,
            'revoked_at' => now(),
        ])->save();

        $pairing->keys()->update([
            'status' => PairingKeyStatus::Revoked,
            'revoked_at' => now(),
        ]);

        $this->activityLogger->log('pairing_revoked', [
            'pairing_id' => $pairing->id,
            'reason' => $reason,
        ], $pairing);

        PairingRevoked::dispatch($pairing);

        return true;
    }
}
