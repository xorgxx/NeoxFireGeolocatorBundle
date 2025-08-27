<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Command;

use Neox\FireGeolocatorBundle\Command\TestProviderCommand;
use Neox\FireGeolocatorBundle\Tests\Traits\HttpClientMockTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TestProviderCommandTest extends TestCase
{
    use HttpClientMockTrait;

    private function command(HttpClientInterface $httpClient, array $config): TestProviderCommand
    {
        return new TestProviderCommand($httpClient, $config);
    }

    public function testListProvidersWhenNone(): void
    {
        $cmd = $this->command($this->mockHttpClient([]), [
            'providers' => ['list' => []],
        ]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute(['--list' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Aucun provider configuré', $tester->getDisplay());
    }

    public function testListProvidersShowsEntries(): void
    {
        $cmd = $this->command($this->mockHttpClient([]), [
            'providers' => ['list' => [
                'findip' => ['dsn' => 'findip+https://example/{ip}'],
            ]],
        ]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute(['--list' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Providers disponibles:', $tester->getDisplay());
        $this->assertStringContainsString('findip', $tester->getDisplay());
    }

    public function testSimulateCompactAndValidateSuccess(): void
    {
        $cmd = $this->command($this->mockHttpClient([]), [
            'providers' => ['list' => ['ipapi' => ['dsn' => 'ipapi+http://ip-api.com/json/{ip}']]],
        ]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute(['provider' => 'ipapi', '--simulate' => true, '--compact' => true, '--validate' => true, 'ip' => '8.8.8.8']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('[ipapi] 8.8.8.8', $tester->getDisplay());
    }

    public function testMissingProviderIsInvalid(): void
    {
        $cmd = $this->command($this->mockHttpClient([]), ['providers' => ['list' => []]]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute([]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Veuillez préciser un alias', $tester->getDisplay());
    }

    public function testFetchErrorFromProvider(): void
    {
        $client = $this->mockHttpClient([
            ['status' => 500, 'json' => '{}'],
        ]);
        $cmd = $this->command($client, [
            'providers' => ['list' => ['ipapi' => ['dsn' => 'ipapi+http://ip-api.com/json/{ip}']]],
        ]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute(['provider' => 'ipapi', 'ip' => '1.2.3.4']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Échec', $tester->getDisplay());
    }

    public function testHealthAllProvidersOk(): void
    {
        $client = $this->mockHttpClient([
            ['status' => 200, 'json' => '{}'],
            ['status' => 200, 'json' => '{}'],
        ]);
        $cmd = $this->command($client, [
            'providers' => [
                'list' => [
                    'ipapi'  => ['dsn' => 'ipapi+http://ip-api.com/json/{ip}'],
                    'findip' => ['dsn' => 'findip+https://api.findip.net/{ip}/?token={token}', 'variables' => ['token' => 'x']],
                ],
            ],
        ]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute(['--health' => true]);
        $this->assertSame(0, $code);
        $disp = $tester->getDisplay();
        $this->assertStringContainsString('[ipapi] OK', $disp);
        $this->assertStringContainsString('[findip] OK', $disp);
    }

    public function testHealthSingleProviderFail(): void
    {
        $client = $this->mockHttpClient([
            ['status' => 500, 'json' => '{}'],
        ]);
        $cmd = $this->command($client, [
            'providers' => [
                'list' => [
                    'ipapi' => ['dsn' => 'ipapi+http://ip-api.com/json/{ip}'],
                ],
            ],
        ]);
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('neox:firegeolocator:test-provider'));
        $code   = $tester->execute(['--health' => true, 'provider' => 'ipapi']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('[ipapi] FAIL', $tester->getDisplay());
    }
}
