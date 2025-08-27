<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Factory;

use Neox\FireGeolocatorBundle\Service\BanManager;
use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;

class BanManagerFactory
{
    public function __construct(private StorageInterface $storage, private array $config)
    {
    }

    public function create(): BanManager
    {
        $cfg = $this->config ?? [];
        $ttl = (int) ($cfg['bans']['ttl'] ?? 3600);
        $max = (int) ($cfg['bans']['max_attempts'] ?? 10);
        $dur = (string) ($cfg['bans']['ban_duration'] ?? '1 hour');

        return new BanManager($this->storage, $ttl, $max, $dur);
    }
}
