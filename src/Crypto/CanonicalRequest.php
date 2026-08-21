<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Crypto;

/**
 * Builds the canonical, stable representation of a request that gets signed
 * and verified for every normal (post-pairing) communication.
 *
 * The representation deliberately covers more than the body, so that method,
 * path, and the pairing/app identifiers cannot be tampered with without
 * invalidating the signature.
 */
final readonly class CanonicalRequest
{
    public function __construct(
        public string $method,
        public string $path,
        public string $timestamp,
        public string $nonce,
        public string $bodyHash,
        public string $appId,
        public string $pairingId,
    ) {}

    public function toString(): string
    {
        return implode("\n", [
            strtoupper($this->method),
            $this->path,
            $this->timestamp,
            $this->nonce,
            $this->bodyHash,
            $this->appId,
            $this->pairingId,
        ]);
    }
}
