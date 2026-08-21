<?php

declare(strict_types=1);

beforeEach(function () {
    $this->envDir = sys_get_temp_dir().'/pairing-key-command-'.uniqid();
    mkdir($this->envDir);
    $this->envPath = $this->envDir.'/.env';
    file_put_contents($this->envPath, "APP_NAME=Test\n");

    $this->app->useEnvironmentPath($this->envDir);
    $this->app->loadEnvironmentFrom('.env');
});

afterEach(function () {
    if (is_file($this->envPath)) {
        unlink($this->envPath);
    }

    if (is_dir($this->envDir)) {
        rmdir($this->envDir);
    }
});

it('generates and saves a new token when none exists', function () {
    $this->artisan('pairing:key')->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('APP_PAIRING_TOKEN=');
});

it('refuses to overwrite an existing token non-interactively without --force', function () {
    file_put_contents($this->envPath, "APP_NAME=Test\nAPP_PAIRING_TOKEN=existing-token\n");

    $this->artisan('pairing:key', ['--no-interaction' => true])->assertFailed();

    expect(file_get_contents($this->envPath))->toContain('APP_PAIRING_TOKEN=existing-token');
});

it('overwrites an existing token with --force', function () {
    file_put_contents($this->envPath, "APP_NAME=Test\nAPP_PAIRING_TOKEN=existing-token\n");

    $this->artisan('pairing:key', ['--force' => true])->assertSuccessful();

    expect(file_get_contents($this->envPath))->not->toContain('APP_PAIRING_TOKEN=existing-token');
});

it('shows an error when --show is used and no token is configured', function () {
    $this->artisan('pairing:key', ['--show' => true])->assertFailed();
});

it('displays the token when --show is used and a token exists', function () {
    file_put_contents($this->envPath, "APP_NAME=Test\nAPP_PAIRING_TOKEN=the-secret-token\n");

    $this->artisan('pairing:key', ['--show' => true])
        ->expectsOutputToContain('the-secret-token')
        ->assertSuccessful();
});

it('provisions a PAIRING_APP_ID automatically when none exists', function () {
    $this->artisan('pairing:key')->assertSuccessful();

    expect(file_get_contents($this->envPath))->toMatch('/PAIRING_APP_ID=[0-9A-Z]{26}/');
});

it('never overwrites an existing PAIRING_APP_ID', function () {
    file_put_contents($this->envPath, "APP_NAME=Test\nPAIRING_APP_ID=01ARZ3NDEKTSV4RRFFQ69G5FAV\n");

    $this->artisan('pairing:key', ['--force' => true])->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('PAIRING_APP_ID=01ARZ3NDEKTSV4RRFFQ69G5FAV');
});

it('provisions PAIRING_APP_ID even when only --show is used', function () {
    $this->artisan('pairing:key', ['--show' => true])->assertFailed();

    expect(file_get_contents($this->envPath))->toMatch('/PAIRING_APP_ID=[0-9A-Z]{26}/');
});
