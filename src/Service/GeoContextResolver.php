<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ProviderResultDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Provider\DsnHttpProvider;
use Neox\FireGeolocatorBundle\Provider\Mapper\IpApiMapper;
use Neox\FireGeolocatorBundle\Provider\Mapper\IpInfoMapper;
use Neox\FireGeolocatorBundle\Provider\Mapper\MaxmindDataMapper;
use Neox\FireGeolocatorBundle\Service\Cache\CacheKey;
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use Neox\FireGeolocatorBundle\Service\Net\IpUtils;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeoContextResolver
{
    private array $breaker         = [];
    private int $breakerThreshold  = 3;      // consecutive failures to open circuit
    private float $breakerCooldown = 30.0;  // seconds before half-open probe

    public function __construct(
        private HttpClientInterface $client,
        private CacheItemPoolInterface $cache,
        private array $providerConfig, // geolocator.providers.list
        private int $contextTtl,
        private GeolocatorLoggerInterface $logger,
    ) {
    }

    public function resolve(Request $request, ResolvedGeoApiConfigDTO $cfg): ?GeoApiContextDTO
    {
        $provider = $cfg->getSelectedProviderAlias($this->providerConfig);
        if (!$provider) {
            return null;
        }

        // 1) Determine IP: trusted headers -> else getClientIp()
        $trustedHeaders = (array) ($cfg->getTrusted()['headers'] ?? []);
        $ip             = $this->getIpFromTrustedHeaders($request, $trustedHeaders)
            ?? ($request->getClientIp() ?: '0.0.0.0');

        // Choose cache key by strategy (session or ip)
        $key = null;
        if (method_exists($cfg, 'getCacheKeyStrategy') && $cfg->getCacheKeyStrategy() === 'session') {
            $sid = $this->getSessionId($request);
            if ($sid) {
                $key  = $this->normalize(CacheKey::ctxSession($provider, $sid));
                $item = $this->cache->getItem($key);
                if ($item->isHit()) {
                    $ctx = $item->get();
                    $this->logger->debug('GeoContext cache hit', ['key' => $key, 'type' => is_object($ctx) ? get_class($ctx) : gettype($ctx)]);
                    // Expose cache status for profiler
                    $request->attributes->set('geolocator_cache', ['key' => $key, 'status' => 'hit']);
                    if ($ctx instanceof GeoApiContextDTO) {
                        return $ctx;
                    }
                    // Backward compatibility: hydrate from array if previous cache stored arrays
                    if (is_array($ctx) && isset($ctx['ip'])) {
                        $dto = new GeoApiContextDTO(
                            ip: (string) ($ctx['ip'] ?? ''),
                            country: $ctx['country']         ?? null,
                            countryCode: $ctx['countryCode'] ?? null,
                            region: $ctx['region']           ?? null,
                            city: $ctx['city']               ?? null,
                            lat: isset($ctx['lat']) ? (float) $ctx['lat'] : null,
                            lon: isset($ctx['lon']) ? (float) $ctx['lon'] : null,
                            isp: $ctx['isp']         ?? null,
                            asn: $ctx['asn']         ?? null,
                            proxy: $ctx['proxy']     ?? null,
                            hosting: $ctx['hosting'] ?? null,
                            raw: is_array($ctx['raw'] ?? null) ? $ctx['raw'] : $ctx
                        );
                        // Re-save as DTO with same remaining TTL if possible
                        try {
                            $item->set($dto);
                            $this->cache->save($item);
                            $this->logger->info('GeoContext cache hydrated legacy value', ['key' => $key]);
                        } catch (\Throwable $e) {
                            $this->logger->warning('GeoContext cache hydration failed', ['key' => $key, 'error' => $e->getMessage()]);
                        }

                        return $dto;
                    }
                } else {
                    $this->logger->debug('GeoContext cache miss', ['key' => $key]);
                    // Expose cache status for profiler
                    $request->attributes->set('geolocator_cache', ['key' => $key, 'status' => 'miss']);
                }
            }
        }

        if (!$key) {
            $key = $this->normalize(CacheKey::ctx($provider, $ip));
        }
        $this->logger->info('GeoContext cache lookup', ['key' => $key, 'provider' => $provider, 'ip' => $ip]);

        // Ensure we have a cache item handle for subsequent save, even when not using session strategy
        if (!isset($item)) {
            $item = $this->cache->getItem($key);
        }

        // Si IP privée/locale, tenter tout de suite de récupérer l'IP publique (avant le calcul de clé)
        if ($this->isPrivateOrLoopbackIp($ip)) {
            $public = $this->fetchPublicIp();
            if (is_string($public) && filter_var($public, FILTER_VALIDATE_IP)) {
                $ip = $public;
            }
        }

        $result = $this->callProvider($provider, $ip);
        if ($result->ok && $result->context) {
            $ttl = $cfg->getCacheTtl() ?? $this->contextTtl;
            $item->set($result->context);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
            // Expose cache status for profiler
            $request->attributes->set('geolocator_cache', ['key' => $key, 'status' => 'save', 'ttl' => $ttl]);
            $this->logger->info('GeoContext cache saved', ['key' => $key, 'ttl' => $ttl]);

            return $result->context;
        } else {
            $this->logger->warning('GeoContext provider failed', ['provider' => $provider]);
        }

        if ($cfg->isProviderFallbackMode()) {
            foreach ($this->providerConfig as $alias => $_) {
                if ($alias === $provider) {
                    continue;
                }
                $this->logger->debug('GeoContext trying fallback provider', ['alias' => $alias, 'ip' => $ip]);
                $result = $this->callProvider($alias, $ip);
                if ($result->ok && $result->context) {
                    $ttl = $cfg->getCacheTtl() ?? $this->contextTtl;
                    // choose fallback key by strategy as well
                    $k2 = null;
                    if (method_exists($cfg, 'getCacheKeyStrategy') && $cfg->getCacheKeyStrategy() === 'session') {
                        $sid = $this->getSessionId($request);
                        if ($sid) {
                            $k2 = $this->normalize(CacheKey::ctxSession($alias, $sid));
                        }
                    }
                    if (!$k2) {
                        $k2 = $this->normalize(CacheKey::ctx($alias, $ip));
                    }
                    $it2 = $this->cache->getItem($k2);
                    $it2->set($result->context);
                    $it2->expiresAfter($ttl);
                    $this->cache->save($it2);
                    // Expose cache status for profiler (fallback save)
                    $request->attributes->set('geolocator_cache', ['key' => $k2, 'status' => 'save', 'ttl' => $ttl]);
                    $this->logger->info('GeoContext cache saved (fallback)', ['key' => $k2, 'ttl' => $ttl, 'provider' => $alias]);

                    return $result->context;
                }
            }
        }

        return null;
    }

    private function callProvider(string $alias, string $ip): ProviderResultDTO
    {
        $def = $this->providerConfig[$alias] ?? null;
        if (!$def) {
            return new ProviderResultDTO(false, null, 'provider_not_found');
        }

        // Circuit breaker: skip fast if open
        $now   = microtime(true);
        $state = $this->breaker[$alias] ?? ['failures' => 0, 'openedUntil' => 0.0, 'halfOpen' => false];
        if ($now < ($state['openedUntil'] ?? 0.0)) {
            $this->logger->warning('Provider circuit open; skipping call', ['provider' => $alias]);

            return new ProviderResultDTO(false, null, 'circuit_open');
        }
        // Allow a half-open probe after cooldown
        if (($state['openedUntil'] ?? 0.0) > 0 && $now >= $state['openedUntil']) {
            $state['halfOpen'] = true;
        }

        $dsn        = $def['dsn'];
        $mapperName = strtolower(substr($dsn, 0, strpos($dsn, '+') ?: 0));
        $mapper     = $this->buildMapper($mapperName);
        $provider   = new DsnHttpProvider($this->client, $dsn, $def['variables'] ?? [], $mapper);

        $start      = microtime(true);
        $result     = $provider->fetch($ip);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($result->ok) {
            // Reset breaker on success
            $this->breaker[$alias] = ['failures' => 0, 'openedUntil' => 0.0, 'halfOpen' => false];
            $this->logger->info('Provider success', ['provider' => $alias, 'ip' => $ip, 'duration_ms' => $durationMs]);

            return $result;
        }

        // Failure: update breaker
        $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
        if ($state['failures'] >= $this->breakerThreshold) {
            $state['openedUntil'] = $now + $this->breakerCooldown;
            $state['halfOpen']    = false;
            $this->logger->error('Provider circuit opened', ['provider' => $alias, 'failures' => $state['failures'], 'cooldown_s' => $this->breakerCooldown, 'error' => $result->error, 'duration_ms' => $durationMs]);
        } else {
            $this->logger->warning('Provider failure', ['provider' => $alias, 'failures' => $state['failures'], 'error' => $result->error, 'duration_ms' => $durationMs]);
        }
        $this->breaker[$alias] = $state;

        return $result;
    }

    private function buildMapper(string $name): object
    {
        return match ($name) {
            'ipapi'  => new IpApiMapper(),
            'findip' => new MaxmindDataMapper(), // FindIpMapper(),
            default  => new IpInfoMapper(),
        };
    }

    private function getSessionId(Request $request): ?string
    {
        try {
            if (!$request->hasSession()) {
                return null;
            }
            $session = $request->getSession();
            if (method_exists($session, 'isStarted') && $session->isStarted()) {
                $id = $session->getId();

                return is_string($id) && $id !== '' ? $id : null;
            }
            // Cookie-first without starting the session
            $rawName = method_exists($session, 'getName') ? $session->getName() : (function_exists('session_name') ? session_name() : null);
            $name    = is_string($rawName) && $rawName !== '' ? $rawName : 'PHPSESSID';
            $cookie  = $request->cookies->get($name);

            return is_string($cookie) && $cookie !== '' ? $cookie : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(string $key): string
    {
        // PSR-6 allowed characters: A-Z a-z 0-9 _ .
        // Replace any other char (including :) by underscore to avoid invalid keys
        return preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
    }

    /**
     * Extrait la première IP valide à partir des headers configurés.
     */
    private function getIpFromTrustedHeaders(Request $request, array $headersAllowed): ?string
    {
        foreach ($headersAllowed as $header) {
            $value = $request->headers->get($header);
            if ($value === null || $value === '') {
                continue;
            }
            $name = strtolower((string) $header);
            if ($name === 'x-forwarded-for') {
                $ip = IpUtils::pickClientIpFromForwarded($value);
            } else {
                $ip = IpUtils::extractSingleIp($value);
            }
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Détermine si l'IP est privée, locale ou loopback.
     */
    private function isPrivateOrLoopbackIp(string $ip): bool
    {
        return IpUtils::isPrivateOrLoopback($ip);
    }

    /**
     * Récupère l'IP publique du serveur via un service externe.
     */
    private function fetchPublicIp(): ?string
    {
        try {
            $resp = $this->client->request('GET', 'https://api.ipify.org?format=json', ['timeout' => 3.0]);
            if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                $data = $resp->toArray(false);
                $ip   = $data['ip'] ?? null;

                return is_string($ip) ? $ip : null;
            }
        } catch (\Throwable) {
            // silencieux: on continue avec l'IP d'origine
        }

        return null;
    }
}
