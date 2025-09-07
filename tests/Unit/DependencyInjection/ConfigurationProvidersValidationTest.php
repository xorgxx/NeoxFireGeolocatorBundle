<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\DependencyInjection;

use Neox\FireGeolocatorBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationProvidersValidationTest extends TestCase
{
    private function process(array $config): array
    {
        $proc = new Processor();

        return $proc->processConfiguration(new Configuration(), [$config]);
    }

    public function testProvidersListMustContainAtLeastOne(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'list' => [],
            ],
        ]);
    }

    public function testDefaultProviderMustExistInList(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'default' => 'missing',
                'list'    => [
                    'findip' => ['dsn' => 'findip+https://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testProviderDsnMustContainPlus(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'list' => [
                    'bad' => ['dsn' => 'findiphttps://api/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testProviderSchemeUnsupported(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'list' => [
                    'bad' => ['dsn' => 'foo+https://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testProviderEndpointMustBeHttp(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'list' => [
                    'bad' => ['dsn' => 'findip+ftp://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testProviderEndpointMustContainIpPlaceholder(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'list' => [
                    'bad' => ['dsn' => 'findip+https://api.example.com/ip', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testFindipRequiresTokenVariable(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'providers' => [
                'list' => [
                    'findip' => ['dsn' => 'findip+https://api.example.com/{ip}'],
                ],
            ],
        ]);
    }

    public function testRetryAfterRejectsNonNumericStrings(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidTypeException::class);
        $this->process([
            'maintenance' => [
                'enabled'     => true,
                'retry_after' => '10s', // non-numeric string should be invalid
            ],
            'providers' => [
                'list' => [
                    'findip' => ['dsn' => 'findip+https://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testRedisDsnInvalidPortRejectedInCache(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'cache' => [
                'redis_dsn' => 'redis://localhost:70000/0',
            ],
            'providers' => [
                'list' => [
                    'findip' => ['dsn' => 'findip+https://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testRedisDsnInvalidHostRejectedInStorage(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->process([
            'storage' => [
                'dsn' => 'redis://:pass@/0', // missing host
            ],
            'providers' => [
                'list' => [
                    'findip' => ['dsn' => 'findip+https://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);
    }

    public function testMaintenanceArraysAreUniqued(): void
    {
        $cfg = $this->process([
            'maintenance' => [
                'allowed_roles'   => ['ROLE_ADMIN', 'ROLE_ADMIN', 'ROLE_USER'],
                'paths_whitelist' => ['/login', '/login', '/healthz'],
            ],
            'providers' => [
                'list' => [
                    'findip' => ['dsn' => 'findip+https://api.example.com/{ip}', 'variables' => ['token' => 't']],
                ],
            ],
        ]);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $cfg['maintenance']['allowed_roles']);
        self::assertSame(['/login', '/healthz'], $cfg['maintenance']['paths_whitelist']);
    }
}
