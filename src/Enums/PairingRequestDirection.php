<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Enums;

enum PairingRequestDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
}
