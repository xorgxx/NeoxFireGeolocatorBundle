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

    public function testCacheKeyStrategyDefaultsToIp(): void
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
        $this->assertSame('ip', $dto->getCacheKeyStrategy());
    }

    public function testCacheKeyStrategyCanBeSession(): void
    {
        $dto = new ResolvedGeoApiConfigDTO(
            enabled: true,
            eventBridgeService: null,
            providerFallbackMode: false,
            redirectOnBan: null,
            logChannel: 'geolocator',
            logLevel: 'warning',
            simulate: false,
            cacheKeyStrategy: 'session',
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
        $this->assertSame('session', $dto->getCacheKeyStrategy());
    }

    public function testTrustedAlwaysContainsArrayKeys(): void
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
            trusted: [], // volontairement vide pour tester les défauts
            exclusions: []
        );

        $trusted = $dto->getTrusted();
        $this->assertIsArray($trusted);
        $this->assertArrayHasKey('headers', $trusted);
        $this->assertArrayHasKey('proxies', $trusted);
        $this->assertArrayHasKey('routes', $trusted);
        $this->assertIsArray($trusted['headers']);
        $this->assertIsArray($trusted['proxies']);
        $this->assertIsArray($trusted['routes']);
    }

    public function testRedirectOnBanNullableAndValue(): void
    {
        // Par défaut null
        $dtoNull = new ResolvedGeoApiConfigDTO(
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
        $this->assertNull($dtoNull->getRedirectOnBan());

        // Valeur non nulle
        $dtoVal = new ResolvedGeoApiConfigDTO(
            enabled: true,
            eventBridgeService: null,
            providerFallbackMode: false,
            redirectOnBan: '/banned',
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
        $this->assertSame('/banned', $dtoVal->getRedirectOnBan());
    }
}
