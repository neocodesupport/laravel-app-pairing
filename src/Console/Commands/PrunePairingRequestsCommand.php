<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Console\Commands;

use Illuminate\Console\Command;
use Neocode\LaravelAppPairing\Services\PairingRequestService;

class PrunePairingRequestsCommand extends Command
{
    protected $signature = 'pairing:prune-requests';

    protected $description = 'Expire pairing requests that have been pending longer than the configured TTL.';

    public function handle(PairingRequestService $requests): int
    {
        $count = $requests->expireStalePending();

        $this->components->info("Expired {$count} stale pairing request(s).");

        return self::SUCCESS;
    }
}
