<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

use Illuminate\Http\JsonResponse;

final class PairingResponses
{
    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    private function __construct() {}
}
