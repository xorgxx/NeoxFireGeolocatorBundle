<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Provider\Mapper;

use Neox\FireGeolocatorBundle\Provider\Mapper\IpApiMapper;
use PHPUnit\Framework\TestCase;

class IpApiMapperTest extends TestCase
{
    public function testMapBasic(): void
    {
        $mapper = new IpApiMapper();
        $ctx    = $mapper->map(['countryCode' => 'FR', 'city' => 'Paris', 'lat' => 48.8, 'lon' => 2.3], '1.2.3.4');
        $this->assertSame('1.2.3.4', $ctx->ip);
        $this->assertSame('FR', $ctx->countryCode);
        $this->assertSame('Paris', $ctx->city);
    }
}
