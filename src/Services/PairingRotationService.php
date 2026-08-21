<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Events\PairingKeyRotated;
use Neocode\LaravelAppPairing\Events\PairingKeyRotationRequested;
use Neocode\LaravelAppPairing\Exceptions\PairingException;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingKeyRotationNotification;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingKey;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;

class PairingRotationService
{
    public function __construct(
        private readonly KeyPairGenerator $keyPairGenerator,
        private readonly PairingActivityLogger $activityLogger,
    ) {}

    /**
     * Rotate this application's own signing key for a pairing. The previous
     * key is revoked for our own future signing immediately, but the
     * remote side is expected to keep verifying it for a short grace period
     * while the rotation notification propagates.
     */
    public function rotate(Pairing $pairing): PairingKey
    {
        if (! $pairing->isActive()) {
            throw new PairingException('Cannot rotate keys for a pairing that is not active.');
        }

        $oldKey = $pairing->activeLocalKey();

        if ($oldKey === null) {
            throw new PairingException("Pairing [{$pairing->pairing_uuid}] has no active local key to rotate.");
        }

        PairingKeyRotationRequested::dispatch($pairing);

        $keyPair = $this->keyPairGenerator->generate();

        $newKey = $pairing->keys()->create([
            'owner' => PairingKeyOwner::Local,
            'public_key' => $keyPair->publicKey,
            'private_key' => $keyPair->privateKey,
            'status' => PairingKeyStatus::Active,
            'activated_at' => now(),
        ]);

        $oldKey->forceFill([
            'status' => PairingKeyStatus::Revoked,
            'revoked_at' => now(),
        ])->save();

        DeliverPairingKeyRotationNotification::dispatch($pairing->id, $oldKey->id, $keyPair->publicKey);

        $this->activityLogger->log('pairing_key_rotated', [
            'pairing_id' => $pairing->id,
            'key_id' => $newKey->id,
        ], $pairing);

        PairingKeyRotated::dispatch($pairing, $newKey);

        return $newKey;
    }

    /**
     * Handle an incoming key rotation notice: the remote side of this
     * pairing has started signing with a new key. Verified by the
     * pairing.auth middleware (against the old or new key, per the grace
     * window) before this runs.
     */
    public function handleIncomingRotation(Pairing $pairing, string $newPublicKey): PairingKey
    {
        $oldRemoteKey = $pairing->activeRemoteKeys()->latest('activated_at')->first();

        $newRemoteKey = $pairing->keys()->create([
            'owner' => PairingKeyOwner::Remote,
            'public_key' => $newPublicKey,
            'status' => PairingKeyStatus::Active,
            'activated_at' => now(),
        ]);

        $oldRemoteKey?->forceFill([
            'status' => PairingKeyStatus::Revoked,
            'revoked_at' => now(),
        ])->save();

        $this->activityLogger->log('pairing_key_rotated', [
            'pairing_id' => $pairing->id,
            'key_id' => $newRemoteKey->id,
            'direction' => 'incoming',
        ], $pairing);

        PairingKeyRotated::dispatch($pairing, $newRemoteKey);

        return $newRemoteKey;
    }
}
