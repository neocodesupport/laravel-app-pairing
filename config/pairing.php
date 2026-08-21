<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Local application identity
    |--------------------------------------------------------------------------
    |
    | These values describe this specific installation. The `app_id` must be
    | unique per installation (two environments of the same codebase, e.g.
    | staging and production, must never share the same app_id) and should be
    | generated once and stored durably (see the `pairing:key` command notes
    | in the README for how to provision it). It is not a secret.
    |
    | You may replace how these values are resolved entirely by binding your
    | own implementation of \Neocode\LaravelAppPairing\Contracts\ApplicationIdentity
    | in your application's service container.
    |
    */

    'identity' => [
        'app_id' => env('PAIRING_APP_ID'),
        'name' => env('PAIRING_APP_NAME', env('APP_NAME', 'Laravel')),
        'base_url' => env('PAIRING_BASE_URL', env('APP_URL', 'http://localhost')),
        'environment' => env('PAIRING_ENVIRONMENT', env('APP_ENV', 'production')),
        'api_version' => env('PAIRING_API_VERSION', 'v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap token
    |--------------------------------------------------------------------------
    |
    | APP_PAIRING_TOKEN is used exclusively to authenticate the *initial*
    | pairing request/response handshake between two applications. It is
    | never used to authenticate normal signed communications, and it never
    | becomes a permanent API key. Manage it with `php artisan pairing:key`.
    |
    */

    'bootstrap_token' => env('APP_PAIRING_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HTTP / transport
    |--------------------------------------------------------------------------
    */

    'https' => [
        'required' => (bool) env('PAIRING_REQUIRE_HTTPS', true),
    ],

    'routes' => [
        'enabled' => (bool) env('PAIRING_ROUTES_ENABLED', true),
        'prefix' => env('PAIRING_ROUTE_PREFIX', 'api/pairing'),
        'version' => env('PAIRING_ROUTE_VERSION', 'v1'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing windows
    |--------------------------------------------------------------------------
    |
    | `signature_ttl` bounds how far a signed request's timestamp may drift
    | from the receiving server's clock, and is also the lifetime of a nonce
    | (a nonce only needs to be remembered for as long as its timestamp would
    | still be considered valid). `pairing_request_ttl` bounds how long an
    | unanswered pairing request stays PENDING before it may be expired.
    |
    */

    'signature_ttl' => (int) env('PAIRING_SIGNATURE_TTL', 300),

    'pairing_request_ttl' => (int) env('PAIRING_REQUEST_TTL', 60 * 60 * 24 * 7),

    'key_rotation_grace_period' => (int) env('PAIRING_KEY_ROTATION_GRACE_PERIOD', 60 * 60),

    /*
    |--------------------------------------------------------------------------
    | Nonce storage
    |--------------------------------------------------------------------------
    */

    'nonce' => [
        'store' => env('PAIRING_NONCE_CACHE_STORE'),
        'prefix' => 'pairing:nonce',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed HTTP client
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('PAIRING_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('PAIRING_HTTP_CONNECT_TIMEOUT', 5),
        'retry_times' => (int) env('PAIRING_HTTP_RETRY_TIMES', 3),
        'retry_delay_ms' => (int) env('PAIRING_HTTP_RETRY_DELAY_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Outbound notifications that are not required to complete the local
    | transaction (revocation, rotation, and status-change notices) are
    | dispatched through these queue settings.
    |
    */

    'queue' => [
        'connection' => env('PAIRING_QUEUE_CONNECTION'),
        'queue' => env('PAIRING_QUEUE', 'default'),
        'tries' => (int) env('PAIRING_QUEUE_TRIES', 5),
        'backoff' => [30, 60, 300, 900, 3600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    */

    'activity_log' => [
        'enabled' => (bool) env('PAIRING_ACTIVITY_LOG_ENABLED', true),
        'log_name' => env('PAIRING_ACTIVITY_LOG_NAME', 'pairing'),
    ],
];
