<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Crypto;

/**
 * A generated Ed25519 key pair, both halves base64-encoded.
 *
 * This value object is only ever held transiently in memory long enough to
 * persist the public key in the clear and the private key encrypted. It must
 * never be logged, serialized to a queue payload, or returned from an API.
 */
final readonly class KeyPair
{
    public function __construct(
        public string $publicKey,
        public string $privateKey,
    ) {}
}
