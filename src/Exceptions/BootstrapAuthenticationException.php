<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Exceptions;

class BootstrapAuthenticationException extends PairingException
{
    public static function missingToken(): self
    {
        return new self('The bootstrap pairing token header is missing.');
    }

    public static function notConfigured(): self
    {
        return new self('No APP_PAIRING_TOKEN is configured on this application.');
    }

    public static function invalidToken(): self
    {
        return new self('The supplied bootstrap pairing token is invalid.');
    }
}
