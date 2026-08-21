<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Contracts;

/**
 * Tracks nonces seen for signed pairing requests to protect against replay
 * attacks. Implementations only need to remember a nonce for the duration of
 * the acceptable timestamp window.
 */
interface NonceStore
{
    /**
     * Record the nonce for the given pairing, returning false if it has
     * already been seen (and is therefore a replay).
     */
    public function remember(string $pairingUuid, string $nonce, int $ttlSeconds): bool;
}
