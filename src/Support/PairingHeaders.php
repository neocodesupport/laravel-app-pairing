<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

final class PairingHeaders
{
    public const string BOOTSTRAP_TOKEN = 'X-Pairing-Bootstrap-Token';

    public const string APP_ID = 'X-Pairing-App-Id';

    public const string PAIRING_ID = 'X-Pairing-Id';

    public const string TIMESTAMP = 'X-Pairing-Timestamp';

    public const string NONCE = 'X-Pairing-Nonce';

    public const string SIGNATURE = 'X-Pairing-Signature';

    private function __construct() {}
}
