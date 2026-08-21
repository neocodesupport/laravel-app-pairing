<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Neocode\LaravelAppPairing\Exceptions\PairingException;
use Neocode\LaravelAppPairing\Services\PairingAcceptanceService;
use Neocode\LaravelAppPairing\Services\PairingRejectionService;
use Neocode\LaravelAppPairing\Services\PairingRequestService;
use Neocode\LaravelAppPairing\Support\PairingResponses;

/**
 * Bootstrap-token-authenticated endpoints for the pairing handshake itself:
 * receiving a new request, and receiving the accept/reject response for a
 * request this application previously sent.
 */
class PairingRequestController extends Controller
{
    public function __construct(
        private readonly PairingRequestService $requests,
        private readonly PairingAcceptanceService $acceptance,
        private readonly PairingRejectionService $rejection,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'request_id' => ['required', 'string', 'max:255'],
            'app_id' => ['required', 'string', 'max:255'],
            'app_name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'url', 'max:2048'],
            'environment' => ['required', 'string', 'max:255'],
            'api_version' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
        ]);

        try {
            $pairingRequest = $this->requests->handleIncomingRequest($payload);
        } catch (PairingException $exception) {
            return PairingResponses::error('pairing_request_rejected', $exception->getMessage(), 409);
        }

        return new JsonResponse(['request_id' => $pairingRequest->request_id, 'status' => $pairingRequest->status->value], 202);
    }

    public function respond(Request $request, string $requestId): JsonResponse
    {
        $status = $request->validate([
            'status' => ['required', 'string', 'in:accepted,rejected'],
        ])['status'];

        try {
            if ($status === 'accepted') {
                $payload = $request->validate([
                    'pairing_id' => ['required', 'string'],
                    'app_id' => ['required', 'string'],
                    'app_name' => ['required', 'string'],
                    'base_url' => ['required', 'string', 'url'],
                    'environment' => ['required', 'string'],
                    'api_version' => ['required', 'string'],
                    'public_key' => ['required', 'string'],
                ]);
                $payload['request_id'] = $requestId;

                $this->acceptance->handleIncomingAcceptance($payload);
            } else {
                $this->rejection->handleIncomingRejection(['request_id' => $requestId]);
            }
        } catch (PairingException $exception) {
            return PairingResponses::error('pairing_response_rejected', $exception->getMessage(), 409);
        }

        return new JsonResponse(['status' => 'ok'], 200);
    }
}
