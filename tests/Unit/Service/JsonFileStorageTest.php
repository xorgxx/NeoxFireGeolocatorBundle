<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\Service\Cache\JsonFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class JsonFileStorageTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $dir        = sys_get_temp_dir();
        $this->file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'geolocator_json_storage_test.json';
        if (file_exists($this->file)) {
            @unlink($this->file);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->file)) {
            @unlink($this->file);
        }
    }

    public function testBanInfoIsNormalizedAndStatsHaveExpectedKeys(): void
    {
        $storage = new JsonFileStorage($this->file, new NullLogger());

        // Minimal ban info without required keys; ttl to force expiration field
        $ok = $storage->banIp('ip-1.2.3.4', ['reason' => 'abuse'], 60);
        $this->assertTrue($ok);

        $info = $storage->getBanInfo('ip-1.2.3.4');
        $this->assertIsArray($info);
        $this->assertArrayHasKey('ip', $info);
        $this->assertArrayHasKey('reason', $info);
        $this->assertArrayHasKey('banned_at', $info);
        $this->assertArrayHasKey('expiration', $info);

        // Stats shape parity
        $stats = $storage->getStats();
        $this->assertArrayHasKey('total_active_bans', $stats);
        $this->assertArrayHasKey('total_active_attempts', $stats);
        $this->assertArrayHasKey('total_permanent_bans', $stats);
        $this->assertArrayHasKey('total_temporary_bans', $stats);
        $this->assertArrayHasKey('last_cleanup', $stats);
        $this->assertSame('json', $stats['storage_type']);
        $this->assertArrayHasKey('file_path', $stats);
        $this->assertArrayHasKey('file_size', $stats);
    }
}
