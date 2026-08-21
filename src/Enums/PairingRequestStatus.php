<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Enums;

enum PairingRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
