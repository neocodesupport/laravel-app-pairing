<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Services\PairingRevocationService;
use Neocode\LaravelAppPairing\Services\PairingRotationService;

/**
 * Endpoints for an already-active pairing, authenticated by the
 * `pairing.auth` middleware (which attaches the resolved Pairing to the
 * request before these actions run).
 */
class PairingController extends Controller
{
    public function __construct(
        private readonly PairingRevocationService $revocation,
        private readonly PairingRotationService $rotation,
        private readonly ApplicationIdentity $identity,
    ) {}

    public function revoke(Request $request): JsonResponse
    {
        $this->revocation->handleIncomingRevocation($this->pairing($request));

        return new JsonResponse(['status' => 'revoked'], 200);
    }

    public function rotateKey(Request $request): JsonResponse
    {
        $publicKey = $request->validate(['public_key' => ['required', 'string']])['public_key'];

        $this->rotation->handleIncomingRotation($this->pairing($request), $publicKey);

        return new JsonResponse(['status' => 'rotated'], 200);
    }

    public function test(Request $request): JsonResponse
    {
        $pairing = $this->pairing($request);

        return new JsonResponse([
            'app_id' => $this->identity->appId(),
            'name' => $this->identity->name(),
            'api_version' => $this->identity->apiVersion(),
            'https' => $request->secure(),
            'pairing_id' => $pairing->pairing_uuid,
            'timestamp' => (string) time(),
        ], 200);
    }

    private function pairing(Request $request): Pairing
    {
        /** @var Pairing $pairing */
        $pairing = $request->attributes->get('pairing');

        return $pairing;
    }
}
