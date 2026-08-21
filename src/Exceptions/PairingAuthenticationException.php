<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Exceptions;

class PairingAuthenticationException extends PairingException
{
    public static function missingHeaders(): self
    {
        return new self('One or more required pairing authentication headers are missing.');
    }

    public static function unknownPairing(): self
    {
        return new self('No matching pairing was found for the supplied app_id and pairing_id.');
    }

    public static function pairingNotActive(): self
    {
        return new self('The pairing is not active.');
    }

    public static function timestampOutOfWindow(): self
    {
        return new self('The request timestamp is outside the acceptable window.');
    }

    public static function nonceReused(): self
    {
        return new self('The request nonce has already been used.');
    }

    public static function invalidSignature(): self
    {
        return new self('The request signature could not be verified.');
    }
}
