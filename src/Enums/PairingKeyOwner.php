<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Enums;

enum PairingKeyOwner: string
{
    case Local = 'local';
    case Remote = 'remote';
}
