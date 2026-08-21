<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Enums;

enum PairingStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
}
