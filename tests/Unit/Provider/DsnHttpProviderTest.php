<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Provider;

use Neox\FireGeolocatorBundle\Provider\DsnHttpProvider;
use Neox\FireGeolocatorBundle\Provider\Mapper\IpApiMapper;
use Neox\FireGeolocatorBundle\Tests\Traits\HttpClientMockTrait;
use PHPUnit\Framework\TestCase;

class DsnHttpProviderTest extends TestCase
{
    use HttpClientMockTrait;

    public function testSuccessMapsContext(): void
    {
        $client = $this->mockHttpClient([
            ['status' => 200, 'json' => json_encode(['country' => 'France', 'countryCode' => 'FR', 'city' => 'Paris', 'lat' => 48.85, 'lon' => 2.35])],
        ]);
        $dsn      = 'ipapi+http://ip-api.com/json/{ip}';
        $provider = new DsnHttpProvider($client, $dsn, [], new IpApiMapper());
        $res      = $provider->fetch('1.2.3.4');
        $this->assertTrue($res->ok);
        $this->assertNotNull($res->context);
        $this->assertSame('FR', $res->context->countryCode);
    }

    public function testHttpError(): void
    {
        $client   = $this->mockHttpClient([['status' => 500, 'json' => '{}']]);
        $provider = new DsnHttpProvider($client, 'ipapi+http://ip-api.com/json/{ip}', [], new IpApiMapper());
        $res      = $provider->fetch('1.2.3.4');
        $this->assertFalse($res->ok);
        $this->assertNull($res->context);
        $this->assertStringContainsString('HTTP', (string) $res->error);
    }
}
