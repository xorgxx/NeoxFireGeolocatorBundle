<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

final class IpOrSessionKeyStrategy implements CacheKeyStrategyInterface
{
    public function __construct(
        private IpAnonymizerInterface $anon,
        private string $algoVersion = 'v1',
        private string $geoCacheKeyStrategy = 'ip'
    ) {
    }

    private function ipHash(?string $ip): ?string
    {
        return $ip ? $this->anon->anonymize($ip) : null;
    }

    public function buildRateKey(?string $ip = null, ?string $sessionId = null): string
    {
        $id = $sessionId ?: $this->ipHash($ip) ?: 'unknown';

        return sprintf('rate_limit:%s:%s', $this->algoVersion, $id);
    }

    public function buildBanKey(?string $ip = null, ?string $sessionId = null): string
    {
        $id = $sessionId ?: $this->ipHash($ip) ?: 'unknown';

        return sprintf('ban:%s:%s', $this->algoVersion, $id);
    }

    public function buildExclusionKey(?string $ip = null, ?string $sessionId = null): string
    {
        $id = $sessionId ?: $this->ipHash($ip) ?: 'unknown';

        return sprintf('exclusion:%s:%s', $this->algoVersion, $id);
    }

    public function buildGeoCacheKey(string $provider, ?string $sessionId = null, ?string $ip = null): string
    {
        if ($this->geoCacheKeyStrategy === 'session' && $sessionId) {
            return sprintf('geo_ctx:session:%s:%s', $provider, $sessionId);
        }
        $hash = $this->ipHash($ip) ?? 'unknown';

        return sprintf('geolocator:geo_ctx:ip:%s:%s:%s', $this->algoVersion, $provider, $hash);
    }
}
