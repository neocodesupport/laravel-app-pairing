<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Crypto\BodyHasher;
use Neocode\LaravelAppPairing\Crypto\Signer;
use Neocode\LaravelAppPairing\Models\Pairing;

class SignedPairingClientFactory
{
    public function __construct(
        private readonly Factory $http,
        private readonly Signer $signer,
        private readonly BodyHasher $bodyHasher,
        private readonly Repository $config,
        private readonly ApplicationIdentity $identity,
    ) {}

    public function make(Pairing $pairing): SignedPairingClient
    {
        return new SignedPairingClient(
            $this->http,
            $this->signer,
            $this->bodyHasher,
            $this->config,
            $this->identity,
            $pairing,
        );
    }
}
