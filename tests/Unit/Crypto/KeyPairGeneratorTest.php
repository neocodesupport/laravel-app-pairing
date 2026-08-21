<?php

declare(strict_types=1);

use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;

it('generates a valid Ed25519 key pair', function () {
    $keyPair = (new KeyPairGenerator)->generate();

    $publicKey = base64_decode($keyPair->publicKey, true);
    $privateKey = base64_decode($keyPair->privateKey, true);

    expect($publicKey)->not->toBeFalse()
        ->and($privateKey)->not->toBeFalse()
        ->and(strlen($publicKey))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
        ->and(strlen($privateKey))->toBe(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
});

it('generates a different key pair every time', function () {
    $generator = new KeyPairGenerator;

    $first = $generator->generate();
    $second = $generator->generate();

    expect($first->publicKey)->not->toBe($second->publicKey)
        ->and($first->privateKey)->not->toBe($second->privateKey);
});

it('produces a public key derivable from the private key', function () {
    $keyPair = (new KeyPairGenerator)->generate();

    $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey(base64_decode($keyPair->privateKey, true));

    expect(base64_encode($derivedPublicKey))->toBe($keyPair->publicKey);
});
