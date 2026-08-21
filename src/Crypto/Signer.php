<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Crypto;

use Neocode\LaravelAppPairing\Exceptions\PairingException;

/**
 * Signs and verifies canonical request representations using Ed25519
 * (libsodium detached signatures). Keys are passed in base64-encoded, as
 * they are stored.
 */
class Signer
{
    public function sign(string $message, string $base64PrivateKey): string
    {
        $privateKey = base64_decode($base64PrivateKey, true);

        if ($privateKey === false || $privateKey === '' || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new PairingException('Cannot sign: the supplied private key is not a valid Ed25519 secret key.');
        }

        $signature = sodium_crypto_sign_detached($message, $privateKey);

        return base64_encode($signature);
    }

    public function verify(string $message, string $base64Signature, string $base64PublicKey): bool
    {
        $signature = base64_decode($base64Signature, true);
        $publicKey = base64_decode($base64PublicKey, true);

        if ($signature === false || $publicKey === false) {
            return false;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
}
