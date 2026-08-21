<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Neocode\LaravelAppPairing\Models\PairingRequest;

final readonly class PairingRejected
{
    use Dispatchable;

    public function __construct(public PairingRequest $request) {}
}
