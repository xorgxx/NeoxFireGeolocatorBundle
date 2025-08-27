<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\DependencyInjection;

use Neox\FireGeolocatorBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationMaintenanceTest extends TestCase
{
    public function testDefaultsAreSetWhenMaintenanceOmitted(): void
    {
        $proc = new Processor();
        $cfg  = $proc->processConfiguration(new Configuration(), []);
        self::assertArrayHasKey('maintenance', $cfg);
        $m = $cfg['maintenance'];
        self::assertFalse($m['enabled']);
        self::assertSame(['ROLE_ADMIN'], $m['allowed_roles']);
        self::assertContains('/login', $m['paths_whitelist']);
        self::assertSame([], $m['ips_whitelist']);
        self::assertSame(600, $m['retry_after']);
        self::assertNull($m['message']);
        self::assertNull($m['template']);
    }

    public function testValidationRetryAfterMinZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $proc = new Processor();
        $proc->processConfiguration(new Configuration(), [[
            'maintenance' => ['enabled' => true, 'retry_after' => -1],
            'providers'   => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
        ]]);
    }
}
