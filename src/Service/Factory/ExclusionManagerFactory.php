<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Factory;

use Neox\FireGeolocatorBundle\Service\ExclusionManager;
use Psr\Cache\CacheItemPoolInterface;

class ExclusionManagerFactory
{
    public function __construct(private CacheItemPoolInterface $cache, private array $config)
    {
    }

    public function create(): ExclusionManager
    {
        $cfg = $this->config ?? [];
        $ttl = (int) ($cfg['cache']['exclusion_ttl'] ?? 3600);

        // ExclusionManager now directly uses PSR-6 CacheItemPoolInterface
        return new ExclusionManager($this->cache, $ttl);
    }
}
