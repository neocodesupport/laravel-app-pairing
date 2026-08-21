<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingKey;

final readonly class PairingKeyRotated
{
    use Dispatchable;

    public function __construct(
        public Pairing $pairing,
        public PairingKey $newKey,
    ) {}
}
