<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\IpFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class IpFilterTest extends TestCase
{
    private function makeRequestWithConfig(array $filters): Request
    {
        $req = new Request();
        $cfg = new ResolvedGeoApiConfigDTO(filters: $filters);
        $req->attributes->set('geolocator_config', $cfg);

        return $req;
    }

    public function testWhitelistAllowsExplicitly(): void
    {
        $req = $this->makeRequestWithConfig([
            'ip' => [
                'default_behavior' => 'deny',
                'rules'            => ['+1.2.3.4'],
            ],
        ]);
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $filter = new IpFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertTrue($res->allowed);
    }

    public function testBlacklistDenies(): void
    {
        $req = $this->makeRequestWithConfig([
            'ip' => [
                'default_behavior' => 'allow',
                'rules'            => ['-10.0.0.0/8'],
            ],
        ]);
        $ctx = new GeoApiContextDTO('10.1.2.3');

        $filter = new IpFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }

    public function testDefaultDenyWhenNoMatch(): void
    {
        $req = $this->makeRequestWithConfig([
            'ip' => [
                'default_behavior' => 'deny',
                'rules'            => [],
            ],
        ]);
        $ctx = new GeoApiContextDTO('8.8.8.8');

        $filter = new IpFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }
}
