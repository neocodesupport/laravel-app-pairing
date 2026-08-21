<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Support\PairingRoutePaths;
use Neocode\LaravelAppPairing\Support\PairingSignedNotifier;
use RuntimeException;

/**
 * Notifies the remote application that this pairing has been revoked.
 * Local revocation already happened synchronously and does not wait on
 * this notification succeeding; it is retried independently.
 */
class DeliverPairingRevocationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(private readonly string $pairingId)
    {
        $this->tries = (int) config('pairing.queue.tries', 5);
        $this->onConnection(config('pairing.queue.connection'));
        $this->onQueue(config('pairing.queue.queue', 'default'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('pairing.queue.backoff', [30, 60, 300, 900, 3600]);
    }

    public function handle(PairingSignedNotifier $notifier, PairingRoutePaths $paths): void
    {
        $pairing = Pairing::query()->find($this->pairingId);

        if ($pairing === null) {
            return;
        }

        $response = $notifier->post($pairing, $paths->revoke($pairing->pairing_uuid), [
            'pairing_id' => $pairing->pairing_uuid,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to deliver revocation notification for pairing [{$pairing->pairing_uuid}]: HTTP {$response->status()}.");
        }
    }
}
