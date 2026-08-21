<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Neocode\LaravelAppPairing\Http\BootstrapPairingClient;
use Neocode\LaravelAppPairing\Support\PairingRoutePaths;
use RuntimeException;

/**
 * Delivers an accept/reject response for a pairing request back to the
 * original requester. This is queued because it is not required for the
 * local accept/reject transaction to succeed, but the requester's side of
 * the pairing cannot activate until it is delivered.
 */
class DeliverPairingResponseNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $requestId,
        private readonly string $remoteBaseUrl,
        private readonly array $payload,
    ) {
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

    public function handle(BootstrapPairingClient $client, PairingRoutePaths $paths): void
    {
        $response = $client->post(
            $this->remoteBaseUrl,
            $paths->pairingRequestResponse($this->requestId),
            $this->payload,
        );

        if ($response->failed()) {
            throw new RuntimeException("Failed to deliver pairing response for request [{$this->requestId}]: HTTP {$response->status()}.");
        }
    }
}
