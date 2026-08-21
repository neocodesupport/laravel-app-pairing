<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

final class PairingHeaders
{
    public const BOOTSTRAP_TOKEN = 'X-Pairing-Bootstrap-Token';

    public const APP_ID = 'X-Pairing-App-Id';

    public const PAIRING_ID = 'X-Pairing-Id';

    public const TIMESTAMP = 'X-Pairing-Timestamp';

    public const NONCE = 'X-Pairing-Nonce';

    public const SIGNATURE = 'X-Pairing-Signature';

    private function __construct() {}
}
