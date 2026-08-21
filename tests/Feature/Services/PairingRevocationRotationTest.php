<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Events\PairingKeyRotated;
use Neocode\LaravelAppPairing\Events\PairingRevoked;
use Neocode\LaravelAppPairing\Exceptions\PairingException;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingKeyRotationNotification;
use Neocode\LaravelAppPairing\Jobs\DeliverPairingRevocationNotification;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Services\PairingRevocationService;
use Neocode\LaravelAppPairing\Services\PairingRotationService;

it('revokes an active pairing immediately and queues a remote notification', function () {
    Bus::fake();
    Event::fake([PairingRevoked::class]);

    $pairing = makeActivePairing();

    app(PairingRevocationService::class)->revoke($pairing);

    expect($pairing->fresh()->status)->toBe(PairingStatus::Revoked)
        ->and($pairing->keys()->where('status', PairingKeyStatus::Active)->count())->toBe(0);

    Bus::assertDispatchedTimes(DeliverPairingRevocationNotification::class, 1);
    Event::assertDispatched(PairingRevoked::class);
});

it('is idempotent when revoking an already-revoked pairing', function () {
    Bus::fake();

    $pairing = makeActivePairing();

    app(PairingRevocationService::class)->revoke($pairing);
    app(PairingRevocationService::class)->revoke($pairing->fresh());

    Bus::assertDispatchedTimes(DeliverPairingRevocationNotification::class, 1);
});

it('allows a brand new pairing request with the same application after revocation', function () {
    $pairing = makeActivePairing();
    Bus::fake();
    app(PairingRevocationService::class)->revoke($pairing);

    expect(
        Pairing::query()
            ->where('remote_application_id', $pairing->remote_application_id)
            ->whereIn('status', [PairingStatus::Pending, PairingStatus::Active])
            ->exists(),
    )->toBeFalse();
});

it('rotates the local signing key, revoking the old one and queuing a notification', function () {
    Bus::fake();
    Event::fake([PairingKeyRotated::class]);

    $pairing = makeActivePairing();
    $oldKey = $pairing->activeLocalKey();

    $newKey = app(PairingRotationService::class)->rotate($pairing);

    expect($newKey->id)->not->toBe($oldKey->id)
        ->and($oldKey->fresh()->status)->toBe(PairingKeyStatus::Revoked)
        ->and($pairing->activeLocalKey()->id)->toBe($newKey->id);

    Bus::assertDispatched(DeliverPairingKeyRotationNotification::class);
    Event::assertDispatched(PairingKeyRotated::class);
});

it('refuses to rotate keys for a pairing that is not active', function () {
    $pairing = makeActivePairing();
    Bus::fake();
    app(PairingRevocationService::class)->revoke($pairing);

    app(PairingRotationService::class)->rotate($pairing->fresh());
})->throws(PairingException::class);

it('handles an incoming key rotation notice by adding the new remote key and revoking the old one', function () {
    $pairing = makeActivePairing();
    $oldRemoteKey = $pairing->activeRemoteKeys()->sole();
    $newPublicKey = (new KeyPairGenerator)->generate()->publicKey;

    $newRemoteKey = app(PairingRotationService::class)->handleIncomingRotation($pairing, $newPublicKey);

    expect($newRemoteKey->public_key)->toBe($newPublicKey)
        ->and($oldRemoteKey->fresh()->status)->toBe(PairingKeyStatus::Revoked)
        ->and($pairing->activeRemoteKeys()->sole()->id)->toBe($newRemoteKey->id);
});
