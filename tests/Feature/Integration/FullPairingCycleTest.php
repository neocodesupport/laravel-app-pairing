<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Models\PairingRequest;
use Neocode\LaravelAppPairing\Services\PairingAcceptanceService;
use Neocode\LaravelAppPairing\Services\PairingCommunicationTestService;
use Neocode\LaravelAppPairing\Services\PairingRequestService;
use Neocode\LaravelAppPairing\Services\PairingRevocationService;
use Neocode\LaravelAppPairing\Services\PairingRotationService;

/**
 * Simulates two independent Laravel installations ("Local" and "Remote"),
 * each with their own database and application identity, exchanging the
 * real signed HTTP protocol through this package's actual routes and
 * middleware. Http::fake() intercepts each outbound call and re-dispatches
 * it through the same route/controller/middleware stack, but with the
 * active DB connection and bound ApplicationIdentity swapped to whichever
 * application the request's URL targets — so each side only ever sees its
 * own database, exactly as if it were a separate application.
 */
function pairingActor(string $appId, string $name, string $baseUrl): ApplicationIdentity
{
    return new class($appId, $name, $baseUrl) implements ApplicationIdentity
    {
        public function __construct(
            private readonly string $appId,
            private readonly string $name,
            private readonly string $baseUrl,
        ) {}

        public function appId(): string
        {
            return $this->appId;
        }

        public function name(): string
        {
            return $this->name;
        }

        public function baseUrl(): string
        {
            return $this->baseUrl;
        }

        public function environment(): string
        {
            return 'testing';
        }

        public function apiVersion(): string
        {
            return 'v1';
        }
    };
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function actingAsPairingActor(string $connection, ApplicationIdentity $identity, Closure $callback): mixed
{
    $previousConnection = config('database.default');
    $previousIdentity = app(ApplicationIdentity::class);

    config(['database.default' => $connection]);
    app()->instance(ApplicationIdentity::class, $identity);

    try {
        return $callback();
    } finally {
        config(['database.default' => $previousConnection]);
        app()->instance(ApplicationIdentity::class, $previousIdentity);
    }
}

beforeEach(function () {
    config(['app.debug' => true]);

    $this->localIdentity = pairingActor('01HZZZ0000000000000LOCALAPP', 'Local App', 'https://local.test');
    $this->remoteIdentity = pairingActor('01HZZZ0000000000REMOTEAPP00', 'Remote App', 'https://remote.test');
    $this->localConnection = config('database.default');

    config(['database.connections.remote' => array_merge(config("database.connections.{$this->localConnection}"), [
        'database' => ':memory:',
    ])]);

    Artisan::call('migrate', ['--database' => 'remote', '--force' => true]);

    Http::fake(function (ClientRequest $request) {
        $isRemote = str_starts_with($request->url(), $this->remoteIdentity->baseUrl());
        $connection = $isRemote ? 'remote' : $this->localConnection;
        $identity = $isRemote ? $this->remoteIdentity : $this->localIdentity;

        [$status, $json] = actingAsPairingActor($connection, $identity, function () use ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            $illuminateRequest = Request::create($path, $request->method(), [], [], [], [], $request->body());
            $illuminateRequest->headers->replace($request->headers());
            $illuminateRequest->headers->set('Content-Type', 'application/json');

            $kernel = app(Kernel::class);
            $response = $kernel->handle($illuminateRequest);
            $kernel->terminate($illuminateRequest, $response);

            // Illuminate\Routing\Route memoizes its resolved controller instance
            // on the route object itself, which is shared across every simulated
            // actor in this test (both "applications" register the same route
            // paths on the one real router). Without resetting it, the second
            // actor to hit a given route would be served the first actor's
            // already-resolved controller (and its captured ApplicationIdentity).
            $route = $illuminateRequest->route();

            if ($route !== null) {
                Closure::bind(function (): void {
                    $this->controller = null;
                }, $route, $route)();
            }

            return [$response->getStatusCode(), json_decode($response->getContent(), true) ?? []];
        });

        return Http::response($json, $status);
    });
});

it('completes the full pairing lifecycle between two applications', function () {
    // 1. Local requests pairing with Remote.
    $outgoingRequest = actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => app(PairingRequestService::class)->requestPairing($this->remoteIdentity->baseUrl()));

    expect($outgoingRequest->status)->toBe(PairingRequestStatus::Pending);

    $localPendingPairing = actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => Pairing::query()->sole());
    expect($localPendingPairing->status)->toBe(PairingStatus::Pending);

    // 2. Remote received the request and it is pending there too.
    $incomingRequest = actingAsPairingActor('remote', $this->remoteIdentity, fn () => PairingRequest::query()->sole());
    expect($incomingRequest->status)->toBe(PairingRequestStatus::Pending)
        ->and($incomingRequest->remote_app_id)->toBe($this->localIdentity->appId());

    // 3. Remote accepts: key exchange happens, both sides end up ACTIVE.
    $remotePairing = actingAsPairingActor('remote', $this->remoteIdentity, fn () => app(PairingAcceptanceService::class)->accept($incomingRequest));
    expect($remotePairing->status)->toBe(PairingStatus::Active);

    $localPairing = actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => Pairing::query()->sole()->fresh());
    expect($localPairing->status)->toBe(PairingStatus::Active)
        ->and($localPairing->pairing_uuid)->toBe($remotePairing->pairing_uuid);

    // 4. Local sends a signed business-style request... exercised via the communication test endpoint.
    $result = actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => app(PairingCommunicationTestService::class)->test($localPairing));
    expect($result['status'])->toBe('success')
        ->and($result['remote_application']['app_id'])->toBe($this->remoteIdentity->appId());

    // 5. Remote can test communication with Local too.
    $remoteResult = actingAsPairingActor('remote', $this->remoteIdentity, fn () => app(PairingCommunicationTestService::class)->test($remotePairing->fresh()));
    expect($remoteResult['status'])->toBe('success')
        ->and($remoteResult['remote_application']['app_id'])->toBe($this->localIdentity->appId());

    // 6. Local rotates its signing key; Remote must accept requests signed with the new key.
    actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => app(PairingRotationService::class)->rotate($localPairing->fresh()));

    $resultAfterRotation = actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => app(PairingCommunicationTestService::class)->test($localPairing->fresh()));
    expect($resultAfterRotation['status'])->toBe('success');

    // 7. Local revokes the pairing; Remote is notified and revokes its side too.
    actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => app(PairingRevocationService::class)->revoke($localPairing->fresh()));

    $localPairing = actingAsPairingActor($this->localConnection, $this->localIdentity, fn () => $localPairing->fresh());
    expect($localPairing->status)->toBe(PairingStatus::Revoked);

    $remotePairing = actingAsPairingActor('remote', $this->remoteIdentity, fn () => $remotePairing->fresh());
    expect($remotePairing->status)->toBe(PairingStatus::Revoked);

    // 8. A signed request is now refused on both sides.
    $resultAfterRevoke = actingAsPairingActor($this->localConnection, $this->localIdentity, function () use ($localPairing) {
        try {
            return app(PairingCommunicationTestService::class)->test($localPairing);
        } catch (Throwable $exception) {
            return $exception;
        }
    });

    expect($resultAfterRevoke)->toBeInstanceOf(Throwable::class);
});
