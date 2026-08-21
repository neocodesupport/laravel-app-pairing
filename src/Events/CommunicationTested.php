<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Neocode\LaravelAppPairing\Models\Pairing;

final readonly class CommunicationTested
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public Pairing $pairing,
        public array $result,
    ) {}
}
