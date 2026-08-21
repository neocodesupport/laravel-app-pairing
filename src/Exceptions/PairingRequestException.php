<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Exceptions;

class PairingRequestException extends PairingException
{
    public static function alreadyResolved(string $requestId): self
    {
        return new self("Pairing request [{$requestId}] has already been resolved.");
    }

    public static function conflictingPairingExists(): self
    {
        return new self('An active or pending pairing already exists with this application.');
    }

    public static function notFound(string $requestId): self
    {
        return new self("Pairing request [{$requestId}] was not found.");
    }

    public static function mismatchedResponse(): self
    {
        return new self('The pairing response does not match the original request.');
    }
}
