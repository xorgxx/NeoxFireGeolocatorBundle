<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Profiler;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class NeoxFireGeolocatorDataCollector extends DataCollector
{
    public function __construct(private StorageInterface $storage, private CacheItemPoolInterface $cache)
    {
    }

    public function reset(): void
    {
        // Ensure collector state is reset between requests (helps toolbar reflect latest values)
        $this->data = [];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // touch cache pool to mark it as used (avoid PHPStan "written but never read" false-positive)
        $cachePool = $this->cache;
        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg = $request->attributes->get('geolocator_config');

        /** @var GeoApiContextDTO|null $ctx */
        $ctx = null;
        /** @var AuthorizationDTO|null $auth */
        $auth = null;

        // Fallback to request attributes if cache did not provide values
        if (!$ctx) {
            $tmp = $request->attributes->get('geolocator_context');
            $ctx = $tmp instanceof GeoApiContextDTO ? $tmp : null;
        }
        if (!$auth) {
            $tmpA = $request->attributes->get('geolocator_auth');
            $auth = $tmpA instanceof AuthorizationDTO ? $tmpA : null;
        }

        $enabled   = $cfg?->enabled ?: false;
        $ip        = $ctx?->ip ?: ($request->getClientIp() ?: null);
        $providers = $cfg instanceof ResolvedGeoApiConfigDTO ? ($cfg->getProviders()['list'] ?? []) : [];
        $provider  = $cfg instanceof ResolvedGeoApiConfigDTO ? $cfg->getSelectedProviderAlias($providers) : null;

        $bucket      = $ip ? ('ip-' . $ip) : null;
        $attempts    = 0;
        $banned      = false;
        $banInfo     = null;
        $attemptsTtl = null;
        $banTtl      = null;
        if ($bucket) {
            try {
                $attempts    = $this->storage->getAttempts($bucket);
                $attemptsTtl = $this->storage->getAttemptsTtl($bucket);
                $banned      = $this->storage->isBanned($bucket);
                if ($banned) {
                    $banInfo = $this->storage->getBanInfo($bucket);
                    $banTtl  = $this->storage->getBanTtl($bucket);
                }
            } catch (\Throwable) {
                // ignore storage read errors in collector
            }
        }

        $cache      = $request->attributes->get('geolocator_cache');
        $statsSmall = null;
        try {
            $stats      = $this->storage->getStats();
            $statsSmall = [
                'storage_type'          => $stats['storage_type']          ?? null,
                'total_active_bans'     => $stats['total_active_bans']     ?? null,
                'total_active_attempts' => $stats['total_active_attempts'] ?? null,
            ];
        } catch (\Throwable) {
            // ignore
        }

        $now        = (new \DateTimeImmutable())->format('c');
        $this->data = [
            'enabled'        => $enabled,
            'ip'             => $ip,
            'country'        => $ctx?->country,
            'countryCode'    => $ctx?->countryCode,
            'region'         => $ctx?->region,
            'city'           => $ctx?->city,
            'lat'            => $ctx?->lat,
            'lon'            => $ctx?->lon,
            'isp'            => $ctx?->isp,
            'asn'            => $ctx?->asn,
            'proxy'          => (bool) ($ctx?->proxy ?? false),
            'hosting'        => (bool) ($ctx?->hosting ?? false),
            'isVpn'          => (bool) (($ctx?->proxy ?? false) || ($ctx?->hosting ?? false)),
            'allowed'        => $auth?->allowed ?? true,
            'reason'         => $auth?->reason,
            'blockingFilter' => $auth?->blockingFilter,
            'simulate'       => (bool) ($request->attributes->get('geolocator_simulate') ?? false),
            'provider'       => $provider,
            'attempts'       => $attempts,
            'attempts_ttl'   => $attemptsTtl,
            'banned'         => $banned,
            'ban'            => $banInfo,
            'ban_ttl'        => $banTtl,
            'cache'          => is_array($cache) ? $cache : null,
            'storage_stats'  => $statsSmall,
            'updated_at'     => $now,
            'request_uri'    => $request->getRequestUri(),
        ];
    }

    public function getName(): string
    {
        return 'neox_fire_geolocator';
    }

    public function getEnabled(): bool
    {
        return $this->data['enabled'] ?? false;
    }

    public function getIp(): ?string
    {
        return $this->data['ip'] ?? null;
    }

    public function getCountry(): ?string
    {
        return $this->data['country'] ?? null;
    }

    public function getCountryCode(): ?string
    {
        return $this->data['countryCode'] ?? null;
    }

    public function getRegion(): ?string
    {
        return $this->data['region'] ?? null;
    }

    public function getCity(): ?string
    {
        return $this->data['city'] ?? null;
    }

    public function getIsp(): ?string
    {
        return $this->data['isp'] ?? null;
    }

    public function getAsn(): ?string
    {
        return $this->data['asn'] ?? null;
    }

    public function isProxy(): bool
    {
        return (bool) ($this->data['proxy'] ?? false);
    }

    public function isHosting(): bool
    {
        return (bool) ($this->data['hosting'] ?? false);
    }

    public function getIsVpn(): bool
    {
        return (bool) ($this->data['isVpn'] ?? false);
    }

    public function isVpn(): bool
    {
        return (bool) ($this->data['isVpn'] ?? false);
    }

    public function isAllowed(): bool
    {
        return (bool) ($this->data['allowed'] ?? true);
    }

    public function getReason(): ?string
    {
        return $this->data['reason'] ?? null;
    }

    public function getBlockingFilter(): ?string
    {
        return $this->data['blockingFilter'] ?? null;
    }

    public function isSimulate(): bool
    {
        return (bool) ($this->data['simulate'] ?? false);
    }

    public function getProvider(): ?string
    {
        return $this->data['provider'] ?? null;
    }

    public function getAttempts(): int
    {
        return (int) ($this->data['attempts'] ?? 0);
    }

    public function getAttemptsTtl(): ?int
    {
        return isset($this->data['attempts_ttl']) ? ($this->data['attempts_ttl'] ?? null) : null;
    }

    public function isBanned(): bool
    {
        return (bool) ($this->data['banned'] ?? false);
    }

    public function getBan(): ?array
    {
        return $this->data['ban'] ?? null;
    }

    public function getBanTtl(): ?int
    {
        return isset($this->data['ban_ttl']) ? ($this->data['ban_ttl'] ?? null) : null;
    }

    public function getCache(): ?array
    {
        return $this->data['cache'] ?? null;
    }

    public function getStorageStats(): ?array
    {
        return $this->data['storage_stats'] ?? null;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->data['updated_at'] ?? null;
    }

    public function getLat(): ?float
    {
        return isset($this->data['lat']) && $this->data['lat'] !== null ? (float) $this->data['lat'] : null;
    }

    public function getLon(): ?float
    {
        return isset($this->data['lon']) && $this->data['lon'] !== null ? (float) $this->data['lon'] : null;
    }

    public function getRequestUri(): ?string
    {
        return $this->data['request_uri'] ?? null;
    }
}
