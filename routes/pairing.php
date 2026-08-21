<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Neocode\LaravelAppPairing\Http\Controllers\PairingController;
use Neocode\LaravelAppPairing\Http\Controllers\PairingRequestController;

if (! config('pairing.routes.enabled', true)) {
    return;
}

$prefix = trim((string) config('pairing.routes.prefix', 'api/pairing'), '/').'/'.trim((string) config('pairing.routes.version', 'v1'), '/');

Route::middleware(config('pairing.routes.middleware', ['api']))
    ->prefix($prefix)
    ->group(function (): void {
        Route::middleware('pairing.bootstrap')->group(function (): void {
            Route::post('pairing-requests', [PairingRequestController::class, 'store'])
                ->name('pairing.requests.store');

            Route::post('pairing-requests/{requestId}/response', [PairingRequestController::class, 'respond'])
                ->name('pairing.requests.respond');
        });

        Route::middleware('pairing.auth')->group(function (): void {
            Route::post('pairings/{pairing}/revoke', [PairingController::class, 'revoke'])
                ->name('pairing.pairings.revoke');

            Route::post('pairings/{pairing}/rotate-key', [PairingController::class, 'rotateKey'])
                ->name('pairing.pairings.rotate-key');

            Route::post('pairings/{pairing}/test', [PairingController::class, 'test'])
                ->name('pairing.pairings.test');
        });
    });
