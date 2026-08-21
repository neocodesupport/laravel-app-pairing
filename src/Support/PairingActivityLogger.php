<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;

/**
 * Thin wrapper around spatie/laravel-activitylog for the discrete
 * security/business events the package needs to journal (pairing
 * requested/accepted/rejected/revoked, key rotation, communication tests,
 * authentication failures). Never pass secrets in $properties.
 */
class PairingActivityLogger
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, array $properties = [], ?Model $subject = null): void
    {
        if (! $this->config->get('pairing.activity_log.enabled', true)) {
            return;
        }

        $activity = ActivityFacade::inLog($this->config->get('pairing.activity_log.log_name', 'pairing'))
            ->event($event)
            ->withProperties($properties);

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        $activity->log($event);
    }
}
