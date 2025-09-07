<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\VpnFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VpnFilterTest extends TestCase
{
    private function makeCfg(array $over = []): ResolvedGeoApiConfigDTO
    {
        $cfg = new ResolvedGeoApiConfigDTO();
        foreach ($over as $k => $v) {
            $cfg->$k = $v;
        }

        return $cfg;
    }

    public function testDenyWhenVpnAndDefaultBlock(): void
    {
        $filter  = new VpnFilter();
        $request = Request::create('/');
        $cfg     = $this->makeCfg([
            'filters' => [
                'vpn' => [
                    'enabled'          => true,
                    'default_behavior' => 'block',
                ],
            ],
        ]);
        $request->attributes->set('geolocator_config', $cfg);
        $ctx  = new GeoApiContextDTO(ip: '1.2.3.4', proxy: true, hosting: false);
        $auth = $filter->decide($request, $ctx);
        self::assertInstanceOf(AuthorizationDTO::class, $auth);
        self::assertFalse($auth->allowed);
    }

    public function testNullWhenVpnButDefaultAllow(): void
    {
        $filter  = new VpnFilter();
        $request = Request::create('/');
        $cfg     = $this->makeCfg([
            'filters' => [
                'vpn' => [
                    'enabled'          => true,
                    'default_behavior' => 'allow',
                ],
            ],
        ]);
        $request->attributes->set('geolocator_config', $cfg);
        $ctx  = new GeoApiContextDTO(ip: '1.2.3.4', proxy: true, hosting: false);
        $auth = $filter->decide($request, $ctx);
        self::assertNull($auth);
    }
}
