<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\Service\BanManager;
use Neox\FireGeolocatorBundle\Service\Cache\JsonFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BanManagerTest extends TestCase
{
    public function testBanAfterMaxAttempts(): void
    {
        $tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'geolocator_test_' . uniqid() . '.json';
        $storage = new JsonFileStorage($tmp, new NullLogger());
        $ban     = new BanManager($storage, ttl: 60, maxAttempts: 3, banDuration: '1 hour');

        $bucket = 'ip:1.2.3.4';
        $this->assertFalse($ban->isBanned($bucket));
        $ban->increment($bucket);
        $ban->increment($bucket);
        $this->assertFalse($ban->isBanned($bucket));
        $ban->increment($bucket);
        $this->assertTrue($ban->isBanned($bucket));

        @unlink($tmp);
    }
}
