<?php

declare(strict_types=1);

use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Crypto\Signer;
use Neocode\LaravelAppPairing\Exceptions\PairingException;

beforeEach(function () {
    $this->signer = new Signer;
    $this->keyPair = (new KeyPairGenerator)->generate();
});

it('verifies a signature produced with the matching key pair', function () {
    $signature = $this->signer->sign('hello world', $this->keyPair->privateKey);

    expect($this->signer->verify('hello world', $signature, $this->keyPair->publicKey))->toBeTrue();
});

it('rejects a signature when the message has been tampered with', function () {
    $signature = $this->signer->sign('hello world', $this->keyPair->privateKey);

    expect($this->signer->verify('goodbye world', $signature, $this->keyPair->publicKey))->toBeFalse();
});

it('rejects a signature verified against the wrong public key', function () {
    $signature = $this->signer->sign('hello world', $this->keyPair->privateKey);
    $otherKeyPair = (new KeyPairGenerator)->generate();

    expect($this->signer->verify('hello world', $signature, $otherKeyPair->publicKey))->toBeFalse();
});

it('rejects a malformed signature without throwing', function () {
    expect($this->signer->verify('hello world', 'not-a-valid-signature', $this->keyPair->publicKey))->toBeFalse();
});

it('rejects a malformed public key without throwing', function () {
    $signature = $this->signer->sign('hello world', $this->keyPair->privateKey);

    expect($this->signer->verify('hello world', $signature, 'not-a-valid-key'))->toBeFalse();
});

it('refuses to sign with an invalid private key', function () {
    $this->signer->sign('hello world', 'not-a-valid-key');
})->throws(PairingException::class);
