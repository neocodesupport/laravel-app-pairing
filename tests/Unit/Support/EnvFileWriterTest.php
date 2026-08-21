<?php

declare(strict_types=1);

use Neocode\LaravelAppPairing\Support\EnvFileWriter;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/pairing-env-writer-test-'.uniqid().'.env';
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

it('returns null when the file does not exist', function () {
    expect((new EnvFileWriter($this->path))->get('APP_PAIRING_TOKEN'))->toBeNull();
});

it('returns null when the key is not present', function () {
    file_put_contents($this->path, "APP_NAME=Example\n");

    expect((new EnvFileWriter($this->path))->get('APP_PAIRING_TOKEN'))->toBeNull();
});

it('reads an existing value', function () {
    file_put_contents($this->path, "APP_NAME=Example\nAPP_PAIRING_TOKEN=abc123\n");

    expect((new EnvFileWriter($this->path))->get('APP_PAIRING_TOKEN'))->toBe('abc123');
});

it('appends the key when the file does not define it yet', function () {
    file_put_contents($this->path, "APP_NAME=Example\n");

    $writer = new EnvFileWriter($this->path);
    $writer->set('APP_PAIRING_TOKEN', 'new-secret');

    expect($writer->get('APP_PAIRING_TOKEN'))->toBe('new-secret')
        ->and(file_get_contents($this->path))->toContain('APP_NAME=Example');
});

it('overwrites an existing value in place without duplicating the key', function () {
    file_put_contents($this->path, "APP_NAME=Example\nAPP_PAIRING_TOKEN=old-secret\nAPP_ENV=testing\n");

    $writer = new EnvFileWriter($this->path);
    $writer->set('APP_PAIRING_TOKEN', 'new-secret');

    $contents = file_get_contents($this->path);

    expect($writer->get('APP_PAIRING_TOKEN'))->toBe('new-secret')
        ->and(substr_count($contents, 'APP_PAIRING_TOKEN='))->toBe(1)
        ->and($contents)->toContain('APP_ENV=testing');
});
