<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Factory;

use Neox\FireGeolocatorBundle\Service\Cache\Sav\CacheRepositoryInterface;
use Neox\FireGeolocatorBundle\Service\Cache\Sav\DoctrineCacheRepository;
use Neox\FireGeolocatorBundle\Service\Cache\Sav\RedisCacheRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CacheRepositoryFactory
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function create(): CacheRepositoryInterface
    {
        /** @var array $cfg */
        $cfg       = $this->container->getParameter('geolocator.config');
        $dsn       = $cfg['cache']['dsn']       ?? null;
        $namespace = $cfg['cache']['namespace'] ?? 'geolocator';

        if ($dsn && str_starts_with($dsn, 'redis://')) {
            $redis = new \Redis();
            $url   = parse_url($dsn);
            $host  = $url['host'] ?? '127.0.0.1';
            $port  = (int) ($url['port'] ?? 6379);
            $redis->connect($host, $port, 1.0);
            if (!empty($url['pass'])) {
                $redis->auth($url['pass']);
            }

            return new RedisCacheRepository($redis, $namespace);
        }

        if ($dsn && str_starts_with($dsn, 'doctrine://')) {
            $connectionName = substr($dsn, strlen('doctrine://'));
            $conn           = $this->container->get('doctrine.dbal.' . ($connectionName ?: 'default') . '_connection');

            return new DoctrineCacheRepository($conn, 'geolocator_cache', $namespace);
        }

        // PSR-6 adapter removed. Require explicit DSN.
        throw new \RuntimeException('Psr6CacheRepository has been removed. Please configure geolocator.cache.dsn with redis:// or doctrine://');
    }
}
