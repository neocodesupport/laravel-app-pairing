<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Contracts;

/**
 * Describes the identity of the local Laravel installation using this package.
 *
 * Host applications may bind their own implementation in the container to
 * source these values from wherever makes sense for them (config, database,
 * a tenant context, ...). The package never assumes a specific host identity.
 */
interface ApplicationIdentity
{
    /**
     * Stable, randomly generated identifier for this specific installation.
     *
     * Two installations of the same codebase (e.g. staging vs production)
     * must never share the same app_id.
     */
    public function appId(): string;

    public function name(): string;

    public function baseUrl(): string;

    public function environment(): string;

    public function apiVersion(): string;
}
