<div align="center">
    <h1>Laravel App Pairing</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/meledjearmel/laravel-app-pairing"><img src="https://img.shields.io/packagist/v/meledjearmel/laravel-app-pairing.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/meledjearmel/laravel-app-pairing"><img src="https://img.shields.io/packagist/php-v/meledjearmel/laravel-app-pairing.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/meledjearmel/laravel-app-pairing"><img src="https://badge.laravel.cloud/badge/meledjearmel/laravel-app-pairing?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/neocodesupport/laravel-app-pairing/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/neocodesupport/laravel-app-pairing/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/meledjearmel/laravel-app-pairing"><img src="https://img.shields.io/packagist/dt/meledjearmel/laravel-app-pairing.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A generic, secure, reusable **pairing and inter-application authentication** layer for Laravel applications. It lets one Laravel installation discover, pair with, and cryptographically authenticate communication against any number of partner Laravel applications — without knowing anything about what those applications are, or what data they exchange.

The package owns exactly five concerns: **identity**, **pairing**, **authentication**, **signing**, and **transport**. It never touches your business data or business endpoints — you keep writing your own controllers and models, and simply protect them with the middleware and signed HTTP client this package provides.

- [Concepts](#concepts)
- [Installation](#installation)
- [Application identity](#application-identity)
- [The bootstrap token](#the-bootstrap-token-app_pairing_token)
- [The `pairing:key` command](#the-pairingkey-command)
- [Pairing lifecycle](#pairing-lifecycle)
  - [1. Requesting a pairing](#1-requesting-a-pairing)
  - [2. Receiving a request](#2-receiving-a-request)
  - [3. Accepting a request](#3-accepting-a-request)
  - [4. Rejecting a request](#4-rejecting-a-request)
  - [5. Revoking a pairing](#5-revoking-a-pairing)
  - [6. Rotating keys](#6-rotating-keys)
- [Sending signed requests](#sending-signed-requests)
- [Protecting your own endpoints](#protecting-your-own-endpoints)
- [Testing communication](#testing-communication)
- [Events](#events)
- [Activity log](#activity-log)
- [Security model](#security-model)
- [Configuration reference](#configuration-reference)
- [Data model](#data-model)
- [Design decisions that differ from a literal reading of the spec](#design-decisions-that-differ-from-a-literal-reading-of-the-spec)
- [Troubleshooting](#troubleshooting)

## Concepts

| Concept | What it is |
|---|---|
| **Application identity** | A stable `app_id` + descriptive metadata (`name`, `base_url`, `environment`, `api_version`) identifying *this* installation. Two environments of the same codebase (staging vs. production) have different `app_id`s. |
| **Bootstrap token (`APP_PAIRING_TOKEN`)** | A shared secret used **only** to authorize the initial pairing handshake. It never authenticates normal traffic and never becomes a permanent API key. |
| **Pairing request** | A proposal from one application to pair with another. Requests are `pending`, `accepted`, `rejected`, or `expired`. |
| **Pairing** | An active, keyed relationship between two applications, identified by a `pairing_uuid` shared by both sides. States: `pending` (reserved for future use — see [design decisions](#design-decisions-that-differ-from-a-literal-reading-of-the-spec)), `active`, `revoked`. |
| **Pairing key** | An Ed25519 key pair. Each pairing tracks both applications' keys, with full rotation history. Private keys are encrypted at rest and never leave the application that generated them. |
| **Signed request** | Every normal (post-pairing) HTTP call is authenticated with `app_id` + `pairing_id` + `timestamp` + `nonce` + a body hash, all covered by an Ed25519 signature — never a bearer token. |

## Installation

```bash
composer require meledjearmel/laravel-app-pairing
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="pairing-config"
```

The package's migrations are loaded automatically — no publishing required. Run them with:

```bash
php artisan migrate
```

If you'd rather have the migration files in your own `database/migrations` directory (e.g. to customize them), publish them explicitly instead:

```bash
php artisan vendor:publish --tag="pairing-migrations"
```

## Application identity

Add these variables to `.env` (see `config/pairing.php` for every option):

```env
PAIRING_APP_ID=            # generated once, see below — not a secret
PAIRING_APP_NAME="My Application"
PAIRING_BASE_URL="${APP_URL}"
PAIRING_ENVIRONMENT="${APP_ENV}"
PAIRING_API_VERSION=v1
```

`PAIRING_APP_ID` must be unique **per installation** — production and staging deployments of the same codebase must use different values. You don't need to generate this by hand: running `php artisan pairing:key` (see [below](#the-pairingkey-command)) provisions it automatically the first time it runs, and never touches it again afterward.

If your application needs to source its identity from somewhere other than config/env (a database row, a tenant context, ...), bind your own implementation of the contract instead:

```php
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;

$this->app->bind(ApplicationIdentity::class, MyApplicationIdentity::class);
```

## The bootstrap token (`APP_PAIRING_TOKEN`)

```env
APP_PAIRING_TOKEN=
```

This secret proves, during the pairing handshake only, that the requester is authorized to propose a pairing. **The same value must be configured on both applications** that are allowed to bootstrap a pairing with each other. It is sent as the `X-Pairing-Bootstrap-Token` header, is never logged, is never returned in a response, and is never used once a pairing is `active`.

## The `pairing:key` command

```bash
php artisan pairing:key            # generate a new token and save it to .env
php artisan pairing:key --show     # display the current token in plain text
php artisan pairing:key --set      # interactively set a token via masked input
php artisan pairing:key --force    # skip the overwrite confirmation
```

- **Every invocation** (regardless of flags) first checks whether `PAIRING_APP_ID` is set in `.env`, and silently generates and saves a ULID there if it isn't. This only ever happens once per installation — an existing `PAIRING_APP_ID` is never regenerated or overwritten, by this or any other flag. There is no `--show`/`--set` pair for it because it isn't a secret; it's just printed to the console the one time it's generated.
- Generation of `APP_PAIRING_TOKEN` uses `random_bytes()` (a CSPRNG), never a custom algorithm.
- Overwriting an existing token asks for confirmation unless `--force` is passed; running non-interactively (`--no-interaction`) without `--force` fails safely instead of hanging or silently overwriting.
- `--set` never accepts the secret as a CLI argument (`--set="..."` is deliberately not supported) because argument values can leak into shell history, process lists, and CI logs. It always prompts with masked input.
- Rotating `APP_PAIRING_TOKEN` never revokes already-active pairings — it only affects future bootstrap handshakes.

## Pairing lifecycle

All of this is driven through the `LaravelAppPairing` facade (or by injecting `Neocode\LaravelAppPairing\LaravelAppPairing`), which your application's own controllers/Livewire components/console commands call into. The package does not ship any UI.

### 1. Requesting a pairing

```php
use Neocode\LaravelAppPairing\Facades\LaravelAppPairing;

$request = LaravelAppPairing::requestPairing('https://partner-app.example.com');
```

This generates an Ed25519 key pair for this new relationship, stores the private half encrypted, and POSTs a bootstrap-token-authenticated request to the partner containing this application's identity and public key. The request starts `pending`.

### 2. Receiving a request

Incoming requests arrive automatically at `POST {prefix}/pairing-requests` (see [configuration](#configuration-reference) for the prefix), authenticated by the bootstrap token, validated, checked for idempotent replays (by `request_id`) and for conflicting existing pairings, and stored as a `pending` `PairingRequest`. Listen for `PairingRequested` to notify an admin, e.g. in your own UI's "incoming requests" list.

### 3. Accepting a request

```php
use Neocode\LaravelAppPairing\Models\PairingRequest;

$pairing = LaravelAppPairing::accept(PairingRequest::findOrFail($id));
```

This generates this application's own key pair for the relationship, activates the pairing locally, and queues delivery of the response (this application's public key + the shared `pairing_id`) back to the original requester. The requester activates its own side once it validates that response matches its original request.

### 4. Rejecting a request

```php
LaravelAppPairing::reject(PairingRequest::findOrFail($id));
```

No pairing is ever created for a rejected request.

### 5. Revoking a pairing

```php
use Neocode\LaravelAppPairing\Models\Pairing;

LaravelAppPairing::revoke(Pairing::findOrFail($id), reason: 'no longer needed');
```

Revocation is **immediate and local** — it never waits on the partner being reachable. The remote notification is queued and retried independently. A revoked pairing never accepts signed requests again; pair again from scratch if the relationship needs to be restored.

### 6. Rotating keys

```php
LaravelAppPairing::rotateKey(Pairing::findOrFail($id));
```

Generates a new local key pair, starts using it immediately for outgoing requests, and notifies the partner (signed with the *previous* key, to prove continuity). The partner keeps accepting the old key for a short grace window (`pairing.key_rotation_grace_period`) while the rotation propagates, so in-flight requests are not spuriously rejected. Rotation never revokes the pairing itself.

## Sending signed requests

Once a pairing is `active`, get a ready-to-use signed HTTP client for it:

```php
$client = LaravelAppPairing::clientFor($pairing);

$response = $client->post('/api/v1/orders', $payload);
$response = $client->get('/api/v1/orders/42');
```

The client automatically sets `app_id`, `pairing_id`, `timestamp`, `nonce`, the body hash, and the Ed25519 signature; applies your configured timeout and retry policy; and enforces HTTPS when `pairing.https.required` is enabled. You never construct these headers yourself, and this is the **only** place business endpoints and the pairing protocol touch — the package has no opinion about what `$payload` contains.

## Protecting your own endpoints

Apply the `pairing.auth` middleware to any route that should only be callable by an authenticated partner:

```php
Route::middleware('pairing.auth')->post('/api/v1/orders', OrderController::class);
```

The middleware verifies the pairing exists and is active, that `app_id`/`pairing_id` match, that the timestamp and nonce are fresh, and that the signature is valid — then makes the resolved `Pairing` available via `$request->attributes->get('pairing')`. Your controller never re-implements any of this.

## Testing communication

```php
$result = LaravelAppPairing::testCommunication($pairing);

// [
//     'status' => 'success',
//     'pairing_id' => '...',
//     'remote_application' => ['app_id' => '...', 'name' => '...', 'api_version' => '...'],
//     'https' => true,
//     'authentication' => 'valid',
//     'signature' => 'valid',
//     'response_time_ms' => 42,
// ]
```

This exercises the real signed protocol end-to-end (not just an HTTP 200) against a dedicated health/authentication endpoint, and reports a structured result you can render in your own "test connection" UI — including a graceful `status: 'failed'` result (with an `error` code) for timeouts, unreachable hosts, or authentication failures, rather than throwing.

## Events

| Event | Fired when |
|---|---|
| `PairingRequested` | An incoming pairing request is received. |
| `PairingAccepted` | A pairing becomes active (on either side). |
| `PairingRejected` | A pairing request is rejected (on either side). |
| `PairingRevoked` | A pairing is revoked (locally or on notice from the partner). |
| `PairingKeyRotationRequested` | A key rotation is about to start. |
| `PairingKeyRotated` | A new key becomes active for a pairing (local rotation or an incoming rotation notice). |
| `CommunicationTested` | A communication test completes, successfully or not. |
| `PairingAuthenticationFailed` | An inbound signed request fails authentication, with the reason code. |

## Activity log

When [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog) is configured (it ships as a dependency of this package), the following events are journaled under the `pairing` log name (configurable): `pairing_requested`, `pairing_accepted`, `pairing_rejected`, `pairing_revoked`, `pairing_key_rotated`, `communication_tested`, `pairing_authentication_failed`, `pairing_request_expired`. Logged context always includes the pairing/request id and remote app metadata — **never** `APP_PAIRING_TOKEN`, `APP_KEY`, private keys, full signatures, or `Authorization`-style headers. Per-request business traffic is intentionally not logged here; use your normal Laravel/infrastructure logs for that.

Disable it entirely with `PAIRING_ACTIVITY_LOG_ENABLED=false`.

> **MySQL/PostgreSQL note**: this package's models use ULID (string) primary
> keys, but `spatie/laravel-activitylog`'s own stock migration types
> `subject_id` as an unsigned bigint, which **truncates ULIDs on strict
> database engines** (SQLite silently tolerates it, which is why this kind of
> bug typically only surfaces in production-like databases). You don't need
> to do anything about this yourself: this package ships its own
> `activity_log` migration that creates the table with a ULID-compatible
> subject morph if it doesn't exist yet, or safely widens `subject_id` if the
> table already exists — including if your application already has
> `spatie/laravel-activitylog` installed and migrated independently of this
> package (for your own, unrelated activity logging). This package's
> migration is intentionally dated far in the future so it always runs last
> in any migration batch, after any migration your own application ships for
> the same table, so it always sees the final state rather than racing it.
> The `causer` morph is left untouched either way (it typically stays an
> auto-incrementing user id, which is fine).

## Security model

- **Cryptography**: Ed25519 via libsodium (`sodium_crypto_sign_keypair`/`sodium_crypto_sign_detached`/`sodium_crypto_sign_verify_detached`). No custom cryptographic primitive is implemented anywhere in this package.
- **Private keys** are encrypted at rest with Laravel's own encrypter (an `encrypted` Eloquent cast, backed by `APP_KEY`), stored in a `TEXT` column, never logged, never serialized into a queued job payload, never returned from any endpoint, and hidden from array/JSON model serialization. **Protecting `APP_KEY` is therefore critical** — anyone who obtains it and the database can decrypt every stored private key.
- **The canonical signed representation** covers `METHOD`, `PATH`, `TIMESTAMP`, `NONCE`, `SHA-256(BODY)`, `APP_ID`, and `PAIRING_ID` — never the body alone, and never headers that aren't explicitly part of this representation.
- **Anti-replay**: every signed request carries a timestamp (checked against `pairing.signature_ttl`) and a nonce (remembered for the same TTL via the cache-backed `NonceStore`, swappable via the `NonceStore` contract). A reused nonce is rejected.
- **Bootstrap vs. normal auth are fully separate mechanisms** — `APP_PAIRING_TOKEN` only ever appears on the `pairing.bootstrap`-protected handshake endpoints; every other endpoint uses `pairing.auth` (Ed25519 signatures).
- **HTTPS** is enforced by both middleware whenever `pairing.https.required` is true (the default).
- **Idempotency**: pairing requests, acceptance, rejection, revocation, and rotation are all safe to retry — matched by `request_id` (requests) or by current state (revocation/rotation are no-ops once already applied).

## Configuration reference

See the fully-commented `config/pairing.php` for every key. The highlights:

| Key | Purpose |
|---|---|
| `identity.*` | This installation's `app_id`, `name`, `base_url`, `environment`, `api_version`. |
| `bootstrap_token` | `APP_PAIRING_TOKEN`. |
| `https.required` | Reject non-HTTPS pairing traffic (default `true`). |
| `routes.prefix` / `routes.version` / `routes.middleware` | Where the package's own routes are mounted. |
| `signature_ttl` | Timestamp/nonce validity window, in seconds (default 300). |
| `pairing_request_ttl` | How long an unanswered pairing request stays `pending` before `pairing:prune-requests` expires it. |
| `key_rotation_grace_period` | How long a rotated-out key is still accepted, in seconds. |
| `http.*` | Timeout, connect timeout, retry count/delay for the signed client and bootstrap client. |
| `queue.*` | Connection/queue/retry policy for revocation and rotation notifications. |
| `activity_log.*` | Enable/disable and name the activity log channel. |

Run `php artisan pairing:prune-requests` on a schedule (or let the package's own daily schedule entry do it) to expire stale pending requests.

## Data model

| Table | Purpose |
|---|---|
| `pairing_applications` | Known applications — exactly one row per installation is flagged `is_local`; every partner gets its own row. |
| `pairing_requests` | The handshake record: `request_id`, direction, remote metadata, status (`pending`/`accepted`/`rejected`/`expired`). |
| `pairing_pairings` | The relationship itself: shared `pairing_uuid`, both applications, status (`pending`/`active`/`revoked`), timestamps. |
| `pairing_keys` | Full key history per pairing, tagged `owner` (`local`/`remote`) and `status` (`active`/`revoked`), so rotations are auditable. |

## Design decisions that differ from a literal reading of the spec

- **The `Pairing` model's `pending` status is not currently reachable in the shipped flow.** A `Pairing` row is created in `pending` the moment an outgoing request is sent (so its generated local key has somewhere to live before the relationship is confirmed), and is deleted if that request is rejected or expires. It only ever becomes visible as `active`. This keeps pairing requests and active pairings cleanly separated, as required, without a dangling intermediate state to reason about.
- **A pairing's own private key material is never deleted on revocation**, only marked `revoked` — this lets the queued revocation notification still be signed correctly even if it executes after the local state has already flipped, without weakening any authentication guarantee (a revoked pairing's incoming requests are always rejected by `pairing.auth` regardless of key status).
- **An incoming revocation notice does not itself queue a new outbound revocation notice.** The side that revoked first already knows; echoing it back would just bounce indefinitely.

## Troubleshooting

- **"No PAIRING_APP_ID is configured"** — run `php artisan pairing:key`, which provisions it automatically (see [Application identity](#application-identity)), or bind a custom `ApplicationIdentity`.
- **"No APP_PAIRING_TOKEN is configured"** — run `php artisan pairing:key` on both applications and make sure they match.
- **`pairing_not_active` on every request** — the pairing was revoked, or you're pointing at the wrong `pairing_id`/`app_id`.
- **`nonce_reused`** — your client retried a request whose nonce had already been consumed; the signed client generates a fresh nonce per attempt automatically, so this usually indicates a client outside this package reusing a signed payload.
- **`timestamp_out_of_window`** — check clock skew between the two applications; the acceptable window is `pairing.signature_ttl` seconds.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel App Pairing! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Armel Meledje](https://github.com/meledjearmel)
- [All Contributors](../../contributors)

## License

Laravel App Pairing is open-sourced software licensed under the [MIT license](LICENSE.md).
