<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

final class AnonymizationService
{
    public function __construct(private IpAnonymizerInterface $ipAnonymizer, private CacheKeyStrategyInterface $keyStrategy, private ContextSanitizerInterface $sanitizer, private string $algoVersion = 'v1')
    {
    }

    public function anonymizeIp(string $ip): string
    {
        return $this->ipAnonymizer->anonymize($ip);
    }

    public function buildRateKey(?string $ip = null, ?string $sid = null): string
    {
        return $this->keyStrategy->buildRateKey($ip, $sid);
    }

    public function buildBanKey(?string $ip = null, ?string $sid = null): string
    {
        return $this->keyStrategy->buildBanKey($ip, $sid);
    }

    public function buildExclusionKey(?string $ip = null, ?string $sid = null): string
    {
        return $this->keyStrategy->buildExclusionKey($ip, $sid);
    }

    public function buildGeoCacheKey(string $provider, ?string $sid = null, ?string $ip = null): string
    {
        return $this->keyStrategy->buildGeoCacheKey($provider, $sid, $ip);
    }

    public function sanitizeContext(array|object $ctx): array
    {
        return $this->sanitizer->sanitize($ctx);
    }

    public function getAlgoVersion(): string
    {
        return $this->algoVersion;
    }
}
