<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\VpnFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VpnFilterTest extends TestCase
{
    private function makeRequestWithConfig(array $filters): Request
    {
        $req = new Request();
        $cfg = new ResolvedGeoApiConfigDTO(filters: $filters);
        $req->attributes->set('geolocator_config', $cfg);

        return $req;
    }

    public function testDeniesWhenVpnDetectedAndDefaultBlock(): void
    {
        $req = $this->makeRequestWithConfig([
            'vpn' => [
                'enabled'          => true,
                'default_behavior' => 'block',
            ],
        ]);
        $ctx = new GeoApiContextDTO('1.2.3.4', proxy: true);

        $filter = new VpnFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }

    public function testReturnsNullWhenVpnDetectedAndDefaultAllow(): void
    {
        $req = $this->makeRequestWithConfig([
            'vpn' => [
                'enabled'          => true,
                'default_behavior' => 'allow',
            ],
        ]);
        $ctx = new GeoApiContextDTO('1.2.3.4', hosting: true);

        $filter = new VpnFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNull($res);
    }

    public function testReturnsNullWhenVpnDisabled(): void
    {
        $req = $this->makeRequestWithConfig([
            'vpn' => [
                'enabled'          => false,
                'default_behavior' => 'block',
            ],
        ]);
        $ctx = new GeoApiContextDTO('1.2.3.4', proxy: true);

        $filter = new VpnFilter();
        $this->assertNull($filter->decide($req, $ctx));
    }
}
