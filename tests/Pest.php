<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Crypto\KeyPairGenerator;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;
use Neocode\LaravelAppPairing\Models\Application;
use Neocode\LaravelAppPairing\Models\Pairing;
use Neocode\LaravelAppPairing\Services\LocalApplicationRegistrar;
use Neocode\LaravelAppPairing\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Shared across multiple test files (Http and Services suites), so it lives
 * here rather than in a single test file: when Pest runs in parallel each
 * test FILE is its own worker process, and top-level functions declared in
 * one file are not visible from another.
 */
function makeActivePairing(): Pairing
{
    $local = app(LocalApplicationRegistrar::class)->resolve();
    $remote = Application::query()->create([
        'app_id' => 'partner-app-id',
        'name' => 'Partner',
        'base_url' => 'https://partner.test',
        'environment' => 'production',
        'api_version' => 'v1',
    ]);

    $pairing = Pairing::query()->create([
        'pairing_uuid' => (string) Str::uuid(),
        'local_application_id' => $local->id,
        'remote_application_id' => $remote->id,
        'status' => PairingStatus::Active,
        'accepted_at' => now(),
    ]);

    $localKeyPair = (new KeyPairGenerator)->generate();
    $remoteKeyPair = (new KeyPairGenerator)->generate();

    $pairing->keys()->create([
        'owner' => PairingKeyOwner::Local,
        'public_key' => $localKeyPair->publicKey,
        'private_key' => $localKeyPair->privateKey,
        'status' => PairingKeyStatus::Active,
        'activated_at' => now(),
    ]);

    $pairing->keys()->create([
        'owner' => PairingKeyOwner::Remote,
        'public_key' => $remoteKeyPair->publicKey,
        'status' => PairingKeyStatus::Active,
        'activated_at' => now(),
    ]);

    return $pairing;
}
