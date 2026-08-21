---
name: laravel-app-pairing-development
description: >
  Configure and apply the Laravel App Pairing package in Laravel applications:
  identity, APP_PAIRING_TOKEN, pairing lifecycle, signed HTTP client, and the
  pairing.auth middleware.
license: MIT
metadata:
  author: Armel Meledje
---

# Laravel App Pairing

Use this skill when a Laravel application needs to integrate the `neocode/laravel-app-pairing` package: a generic, cryptographically-authenticated pairing layer between Laravel installations. The package owns identity, pairing, authentication, signing, and transport only — it has no opinion about business data or business endpoints.

## Primary Goal

- apply the `neocode/laravel-app-pairing` package's public API in the smallest correct way, without reimplementing anything it already provides (signing, verification, key storage, retries, idempotency)

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project (`^12.0||^13.0`) on PHP `^8.2` with `ext-sodium` available
- check whether `config/pairing.php` and the `pairing_*` migrations already exist before installing again

### 2. Install and configure

```bash
composer require neocode/laravel-app-pairing
php artisan vendor:publish --tag="pairing-config"
php artisan migrate
```

Set this installation's identity in `.env` — `PAIRING_APP_ID` must be unique per environment (staging ≠ production), and is not a secret. Don't generate it by hand: running `pairing:key` (below) provisions it automatically the first time, and never touches it again afterward.

```env
PAIRING_APP_ID=            # auto-provisioned by `pairing:key` on first run
PAIRING_APP_NAME="My Application"
PAIRING_BASE_URL="${APP_URL}"
PAIRING_ENVIRONMENT="${APP_ENV}"
PAIRING_API_VERSION=v1
```

If identity must come from somewhere other than config/env (a DB row, a tenant context), bind a custom implementation instead of setting these:

```php
$this->app->bind(\Neocode\LaravelAppPairing\Contracts\ApplicationIdentity::class, MyApplicationIdentity::class);
```

Provision the bootstrap secret — the **same value** must be set on both applications that are allowed to pair with each other, and it is never used once a pairing is active. Every invocation below also provisions `PAIRING_APP_ID` automatically if it isn't set yet:

```bash
php artisan pairing:key            # generate + save to .env
php artisan pairing:key --show     # display the current value
php artisan pairing:key --set      # set it interactively (masked input)
php artisan pairing:key --force    # skip the overwrite confirmation
```

### 3. Drive the pairing lifecycle through the facade

Never build pairing requests, signatures, or headers by hand — always go through `LaravelAppPairing` (or inject the `Neocode\LaravelAppPairing\LaravelAppPairing` class):

```php
use Neocode\LaravelAppPairing\Facades\LaravelAppPairing;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingRequest;

LaravelAppPairing::requestPairing('https://partner-app.example.com');   // outgoing
LaravelAppPairing::accept(PairingRequest::findOrFail($id));              // incoming, admin action
LaravelAppPairing::reject(PairingRequest::findOrFail($id));
LaravelAppPairing::revoke(Pairing::findOrFail($id), reason: '...');
LaravelAppPairing::rotateKey(Pairing::findOrFail($id));
LaravelAppPairing::testCommunication(Pairing::findOrFail($id));
```

Incoming pairing requests, accept/reject responses, revocations, and rotations from partners arrive automatically at the package's own routes (default prefix `api/pairing/v1`, configurable via `pairing.routes.*`) — do not add your own routes for these.

React to lifecycle changes with the package's events instead of polling: `PairingRequested`, `PairingAccepted`, `PairingRejected`, `PairingRevoked`, `PairingKeyRotationRequested`, `PairingKeyRotated`, `CommunicationTested`, `PairingAuthenticationFailed`.

### 4. Call partner endpoints with the signed client

```php
$client = LaravelAppPairing::clientFor($pairing);

$response = $client->post('/api/v1/orders', $payload);
$response = $client->get('/api/v1/orders/42');
```

This is the **only** place your business code should touch the pairing protocol — the client handles `app_id`, `pairing_id`, timestamp, nonce, body hash, signature, HTTPS enforcement, timeout, and retries for you.

### 5. Protect your own endpoints

```php
Route::middleware('pairing.auth')->post('/api/v1/orders', OrderController::class);
```

Inside the controller, get the authenticated pairing from `$request->attributes->get('pairing')` — never re-validate the signature yourself. Never protect these routes with `pairing.bootstrap`; that middleware exists solely for the package's own handshake endpoints.

### 6. Keep pairing requests from piling up

Schedule (or rely on the package's own daily schedule entry for) pruning of stale, unanswered requests:

```bash
php artisan pairing:prune-requests
```

## Rules, References, and Templates

Read before executing:

- `README.md` — full usage guide, security model, configuration reference, and data model
- `config/pairing.php` — every configurable key, fully commented
- `src/LaravelAppPairing.php` — the complete public API surface (the facade target)
- `src/Contracts/ApplicationIdentity.php` — the contract to implement for a custom identity source
- `src/Events/` — the full event list
- `tests/Feature/Integration/FullPairingCycleTest.php` — a worked example of the entire lifecycle between two applications

## Examples

- **New Laravel app pairing with an existing partner**: install the package, set `PAIRING_APP_ID`/`PAIRING_APP_NAME`/`PAIRING_BASE_URL`, run `pairing:key` on both sides with the same token, call `LaravelAppPairing::requestPairing($partnerBaseUrl)` from an admin action, then have the partner's admin call `LaravelAppPairing::accept($request)` — assert the resulting `Pairing::status` is `active` and both `pairing_keys` rows exist.
- **Calling a partner's business endpoint**: `LaravelAppPairing::clientFor($pairing)->post('/api/v1/orders', $payload)`, then assert on the returned `Illuminate\Http\Client\Response` exactly as with any other HTTP client call.
- **Protecting an inbound business endpoint**: add `pairing.auth` to the route, then in a feature test send a request signed with a test pairing's known private key (see `tests/Feature/Http/AuthenticatePairingRequestTest.php` for the exact canonical-string construction) and assert `200`; assert `401` with an invalid signature.

## Anti-patterns

- Do not construct pairing HTTP headers, timestamps, nonces, or signatures manually — always use `LaravelAppPairing::clientFor($pairing)`.
- Do not authenticate normal business traffic with `APP_PAIRING_TOKEN` or the `pairing.bootstrap` middleware — that token is bootstrap-only.
- Do not add custom routes for pairing requests/responses/revocation/rotation — the package already registers them.
- Do not store, log, or return a pairing's private key, `APP_PAIRING_TOKEN`, or `APP_KEY` anywhere in application code.
- Do not document package internals (services, jobs, support classes) here; keep this skill focused on the public facade, middleware, config, commands, and events a consuming app actually touches.
