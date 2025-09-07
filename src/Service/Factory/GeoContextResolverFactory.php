<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Factory;

use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;
use Neox\FireGeolocatorBundle\Service\Context\GeoContextHydratorInterface;
use Neox\FireGeolocatorBundle\Service\GeoContextResolver;
// use Symfony\Component\HttpClient\RetryableHttpClient;/
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use Neox\FireGeolocatorBundle\Service\Privacy\AnonymizationService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeoContextResolverFactory
{
    public function __construct(
        private HttpClientInterface $client,
        private CacheItemPoolInterface $cache,
        private array $config, // <- injecte %geolocator.config%
        private GeolocatorLoggerInterface $logger,
        private StorageInterface $storage,
        private AnonymizationService $privacy,
        private GeoContextHydratorInterface $ctxHydrator,
    ) {
    }

    public function create(): GeoContextResolver
    {
        $providers = $this->config['providers']['list'] ?? [];
        $ttl       = (int) ($this->config['cache']['context_ttl'] ?? 300);

        // Wrap the base client with a retryable client for resilience (2 retries, exponential backoff by default)
        $client = new RetryableHttpClient($this->client, null, 2);

        return new GeoContextResolver($client, $this->cache, $providers, $ttl, $this->logger, $this->storage, $this->privacy, $this->ctxHydrator, (bool) ($this->config['privacy']['enable_dual_read'] ?? true));
    }
}
