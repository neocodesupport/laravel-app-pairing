<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Neocode\LaravelAppPairing\LaravelAppPairing;

it('resolves the singleton', function () {
    expect(app(LaravelAppPairing::class))->toBeInstanceOf(LaravelAppPairing::class);
});

it('returns the same instance from the container', function () {
    expect(app(LaravelAppPairing::class))->toBe(app(LaravelAppPairing::class));
});

it('merges the package config', function () {
    expect(config('pairing.routes.prefix'))->toBe('api/pairing');
});

it('registers the pairing:key artisan command', function () {
    expect(array_keys(Artisan::all()))->toContain('pairing:key');
});
