<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Services;

use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;
use Neocode\LaravelAppPairing\Models\Application;

/**
 * Keeps the single "is_local" Application row in sync with whatever
 * ApplicationIdentity is bound in the container.
 */
class LocalApplicationRegistrar
{
    public function __construct(private readonly ApplicationIdentity $identity) {}

    public function resolve(): Application
    {
        $application = Application::query()->firstOrNew(['app_id' => $this->identity->appId()]);

        $application->fill([
            'name' => $this->identity->name(),
            'base_url' => $this->identity->baseUrl(),
            'environment' => $this->identity->environment(),
            'api_version' => $this->identity->apiVersion(),
            'is_local' => true,
        ]);

        if ($application->isDirty()) {
            $application->save();
        }

        return $application;
    }
}
