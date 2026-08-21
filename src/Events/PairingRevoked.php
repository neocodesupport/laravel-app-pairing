<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Neocode\LaravelAppPairing\Models\Pairing;

final readonly class PairingRevoked
{
    use Dispatchable;

    public function __construct(public Pairing $pairing) {}
}
