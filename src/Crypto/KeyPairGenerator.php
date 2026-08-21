<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Crypto;

/**
 * Generates Ed25519 signing key pairs using libsodium. No cryptographic
 * primitive is implemented by this package itself.
 */
class KeyPairGenerator
{
    public function generate(): KeyPair
    {
        $keyPair = sodium_crypto_sign_keypair();

        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $privateKey = sodium_crypto_sign_secretkey($keyPair);

        return new KeyPair(
            publicKey: base64_encode($publicKey),
            privateKey: base64_encode($privateKey),
        );
    }
}
