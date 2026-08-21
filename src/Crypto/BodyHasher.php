<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Crypto;

class BodyHasher
{
    public function hash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }
}
