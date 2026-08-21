<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Neocode\LaravelAppPairing\Contracts\NonceStore;

class CacheNonceStore implements NonceStore
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    public function remember(string $pairingUuid, string $nonce, int $ttlSeconds): bool
    {
        $key = sprintf('%s:%s:%s', $this->config->get('pairing.nonce.prefix', 'pairing:nonce'), $pairingUuid, $nonce);

        $store = $this->cache->store($this->config->get('pairing.nonce.store'));

        return $store->add($key, true, $ttlSeconds);
    }
}
