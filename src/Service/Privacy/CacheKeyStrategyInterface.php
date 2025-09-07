<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

interface CacheKeyStrategyInterface
{
    public function buildRateKey(?string $ip = null, ?string $sessionId = null): string;

    public function buildBanKey(?string $ip = null, ?string $sessionId = null): string;

    public function buildExclusionKey(?string $ip = null, ?string $sessionId = null): string;

    public function buildGeoCacheKey(string $provider, ?string $sessionId = null, ?string $ip = null): string;
}
