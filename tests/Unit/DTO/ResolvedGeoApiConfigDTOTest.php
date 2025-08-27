<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\DTO;

use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use PHPUnit\Framework\TestCase;

final class ResolvedGeoApiConfigDTOTest extends TestCase
{
    public function testExclusionsKeyStrategyDefaultsToIp(): void
    {
        $dto = new ResolvedGeoApiConfigDTO(
            enabled: true,
            eventBridgeService: null,
            providerFallbackMode: false,
            redirectOnBan: null,
            logChannel: 'geolocator',
            logLevel: 'warning',
            simulate: false,
            cacheKeyStrategy: 'ip',
            provider: null,
            forceProvider: null,
            cacheTtl: null,
            blockOnError: true,
            exclusionKey: null,
            providers: ['default' => 'findip', 'list' => []],
            storage: ['dsn' => null],
            bans: ['max_attempts' => 10, 'ttl' => 3600, 'ban_duration' => '1 hour'],
            filters: [],
            trusted: ['headers' => [], 'proxies' => [], 'routes' => []],
            exclusions: []
        );
        $this->assertSame('ip', $dto->getExclusionsKeyStrategy());
    }

    public function testExclusionsKeyStrategySession(): void
    {
        $dto = new ResolvedGeoApiConfigDTO(
            enabled: true,
            eventBridgeService: null,
            providerFallbackMode: false,
            redirectOnBan: null,
            logChannel: 'geolocator',
            logLevel: 'warning',
            simulate: false,
            cacheKeyStrategy: 'ip',
            provider: null,
            forceProvider: null,
            cacheTtl: null,
            blockOnError: true,
            exclusionKey: null,
            providers: ['default' => 'findip', 'list' => []],
            storage: ['dsn' => null],
            bans: ['max_attempts' => 10, 'ttl' => 3600, 'ban_duration' => '1 hour'],
            filters: [],
            trusted: ['headers' => [], 'proxies' => [], 'routes' => []],
            exclusions: ['key_strategy' => 'session']
        );
        $this->assertSame('session', $dto->getExclusionsKeyStrategy());
    }
}
