<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Neocode\LaravelAppPairing\LaravelAppPairing
 */
class LaravelAppPairing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Neocode\LaravelAppPairing\LaravelAppPairing::class;
    }
}
