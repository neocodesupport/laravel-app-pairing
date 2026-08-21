<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Neocode\LaravelAppPairing\Support\PairingHeaders;
use Neocode\LaravelAppPairing\Support\PairingResponses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the initial pairing handshake (request submission and the
 * accept/reject response callback) using APP_PAIRING_TOKEN. Never used for
 * normal, post-pairing signed communications.
 */
class EnsureBootstrapToken
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('pairing.https.required') && ! $request->secure()) {
            return PairingResponses::error('https_required', 'HTTPS is required for pairing endpoints.', 400);
        }

        $configuredToken = $this->config->get('pairing.bootstrap_token');

        if (! is_string($configuredToken) || $configuredToken === '') {
            return PairingResponses::error('bootstrap_token_not_configured', 'This application has no APP_PAIRING_TOKEN configured.', 503);
        }

        $providedToken = $request->header(PairingHeaders::BOOTSTRAP_TOKEN);

        if (! is_string($providedToken) || $providedToken === '') {
            return PairingResponses::error('bootstrap_token_missing', 'The bootstrap pairing token header is missing.', 401);
        }

        if (! hash_equals($configuredToken, $providedToken)) {
            return PairingResponses::error('bootstrap_token_invalid', 'The supplied bootstrap pairing token is invalid.', 401);
        }

        return $next($request);
    }
}
