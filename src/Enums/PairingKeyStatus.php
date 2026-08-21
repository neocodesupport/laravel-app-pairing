<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Enums;

enum PairingKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
