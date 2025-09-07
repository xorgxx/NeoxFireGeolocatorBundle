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

    public function testMaintenanceExplicitOverrideValues(): void
    {
        $proc = new Processor();
        $cfg  = $proc->processConfiguration(new Configuration(), [[
            'maintenance' => [
                'enabled'         => true,
                'allowed_roles'   => ['ROLE_SUPER_ADMIN', 'ROLE_SUPPORT'],
                'paths_whitelist' => ['/login', '/_profiler', '/custom'],
                'ips_whitelist'   => ['127.0.0.1', '10.0.0.0/8'],
                'retry_after'     => 300,
                'message'         => 'Maintenance planifiée',
                'template'        => '@NeoxFireGeolocator/maintenance.html.twig',
            ],
            'providers' => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
        ]]);

        $m = $cfg['maintenance'];
        self::assertTrue($m['enabled']);
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_SUPPORT'], $m['allowed_roles']);
        self::assertContains('/custom', $m['paths_whitelist']);
        self::assertSame(['127.0.0.1', '10.0.0.0/8'], $m['ips_whitelist']);
        self::assertSame(300, $m['retry_after']);
        self::assertSame('Maintenance planifiée', $m['message']);
        self::assertSame('@NeoxFireGeolocator/maintenance.html.twig', $m['template']);
    }

    public function testRetryAfterAcceptsNumericString(): void
    {
        $proc = new Processor();
        $cfg  = $proc->processConfiguration(new Configuration(), [[
            'maintenance' => [
                'enabled'     => true,
                'retry_after' => '900',
            ],
            'providers' => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
        ]]);

        self::assertSame(900, $cfg['maintenance']['retry_after']);
    }

    public function testAllowedRolesMustBeStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $proc = new Processor();
        $proc->processConfiguration(new Configuration(), [[
            'maintenance' => [
                'allowed_roles' => ['ROLE_ADMIN', 123], // invalide si le schéma exige des strings
            ],
            'providers' => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
        ]]);
    }

    public function testPathsWhitelistMustBeStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $proc = new Processor();
        $proc->processConfiguration(new Configuration(), [[
            'maintenance' => [
                'paths_whitelist' => ['/ok', null], // invalide si le schéma exige des strings non nuls
            ],
            'providers' => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
        ]]);
    }

    public function testIpsWhitelistAcceptsIpAndCidrButRejectsNonString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $proc = new Processor();
        $proc->processConfiguration(new Configuration(), [[
            'maintenance' => [
                'ips_whitelist' => ['127.0.0.1', '10.0.0.0/8', ['not', 'a', 'string']], // invalide si non-string
            ],
            'providers' => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
        ]]);
    }

    public function testMergingMultipleConfigBlocks(): void
    {
        $proc = new Processor();
        $cfg  = $proc->processConfiguration(new Configuration(), [
            [
                'maintenance' => [
                    'enabled'         => false,
                    'retry_after'     => 600,
                    'allowed_roles'   => ['ROLE_ADMIN'],
                    'paths_whitelist' => ['/login'],
                ],
                'providers' => ['list' => ['findip' => ['dsn' => 'findip+https://x/{ip}?t=1', 'variables' => ['token' => 'x']]]],
            ],
            [
                'maintenance' => [
                    'enabled'         => true,            // override
                    'retry_after'     => 120,             // override
                    'allowed_roles'   => ['ROLE_SUPPORT'], // override (remplacement)
                    'paths_whitelist' => ['/healthz'],     // override (remplacement vs merge selon votre Tree)
                ],
            ],
        ]);

        $m = $cfg['maintenance'];
        self::assertTrue($m['enabled']);
        self::assertSame(120, $m['retry_after']);
        self::assertSame(['ROLE_SUPPORT'], $m['allowed_roles']);
        self::assertContains('/healthz', $m['paths_whitelist']);
        self::assertNotContains('/login', $m['paths_whitelist']); // si l’arbre remplace l’intégralité du tableau
    }
}
