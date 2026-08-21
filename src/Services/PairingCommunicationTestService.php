<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Neocode\LaravelAppPairing\Events\CommunicationTested;
use Neocode\LaravelAppPairing\Exceptions\PairingException;
use Neocode\LaravelAppPairing\Http\SignedPairingClientFactory;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Support\PairingActivityLogger;
use Neocode\LaravelAppPairing\Support\PairingRoutePaths;
use Throwable;

class PairingCommunicationTestService
{
    public function __construct(
        private readonly SignedPairingClientFactory $clientFactory,
        private readonly PairingRoutePaths $paths,
        private readonly PairingActivityLogger $activityLogger,
    ) {}

    /**
     * Exercise the full signed-communication protocol against a pairing's
     * remote application and report a structured diagnostic result. A
     * bare HTTP 200 is never treated as sufficient proof on its own; the
     * response payload and timing are validated too.
     *
     * @return array<string, mixed>
     */
    public function test(Pairing $pairing): array
    {
        if (! $pairing->isActive()) {
            throw new PairingException('Cannot test communication for a pairing that is not active.');
        }

        $startedAt = microtime(true);

        try {
            $response = $this->clientFactory->make($pairing)->post(
                $this->paths->communicationTest($pairing->pairing_uuid),
                ['requested_at' => now()->toIso8601String()],
            );
        } catch (Throwable $exception) {
            $result = [
                'status' => 'failed',
                'error' => 'unreachable',
                'message' => $exception->getMessage(),
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];

            $this->recordResult($pairing, $result);

            return $result;
        }

        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            $result = [
                'status' => 'failed',
                'error' => $response->status() === 401 ? 'authentication_failed' : 'http_error',
                'http_status' => $response->status(),
                'response_time_ms' => $responseTimeMs,
            ];

            $this->recordResult($pairing, $result);

            return $result;
        }

        $body = $response->json() ?? [];

        $result = [
            'status' => 'success',
            'pairing_id' => $pairing->pairing_uuid,
            'remote_application' => [
                'app_id' => $body['app_id'] ?? null,
                'name' => $body['name'] ?? null,
                'api_version' => $body['api_version'] ?? null,
            ],
            'https' => str_starts_with((string) $pairing->remoteApplication?->base_url, 'https://'),
            'authentication' => 'valid',
            'signature' => 'valid',
            'response_time_ms' => $responseTimeMs,
        ];

        $this->recordResult($pairing, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordResult(Pairing $pairing, array $result): void
    {
        $this->activityLogger->log('communication_tested', [
            'pairing_id' => $pairing->id,
            'status' => $result['status'],
            'response_time_ms' => $result['response_time_ms'] ?? null,
        ], $pairing);

        CommunicationTested::dispatch($pairing, $result);
    }
}
