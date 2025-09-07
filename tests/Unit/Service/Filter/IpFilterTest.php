<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\IpFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class IpFilterTest extends TestCase
{
    private function makeCfg(array $over = []): ResolvedGeoApiConfigDTO
    {
        $cfg = new ResolvedGeoApiConfigDTO();
        foreach ($over as $k => $v) {
            $cfg->$k = $v;
        }

        return $cfg;
    }

    public function testAllowWhenIpWhitelisted(): void
    {
        $filter  = new IpFilter();
        $request = Request::create('/');
        $request->server->set('REMOTE_ADDR', '1.2.3.4');
        $cfg = $this->makeCfg([
            'filters' => [
                'ip' => [
                    'default_behavior' => 'block',
                    'rules'            => ['+1.2.3.4'],
                ],
            ],
        ]);
        $request->attributes->set('geolocator_config', $cfg);
        $auth = $filter->decide($request, new GeoApiContextDTO(ip: '1.2.3.4'));
        self::assertInstanceOf(AuthorizationDTO::class, $auth);
        self::assertTrue($auth->allowed);
    }

    public function testDefaultBlockWhenNoRules(): void
    {
        $filter  = new IpFilter();
        $request = Request::create('/');
        $request->server->set('REMOTE_ADDR', '5.6.7.8');
        $cfg = $this->makeCfg([
            'filters' => [
                'ip' => [
                    'default_behavior' => 'block',
                    'rules'            => [],
                ],
            ],
        ]);
        $request->attributes->set('geolocator_config', $cfg);
        $auth = $filter->decide($request, new GeoApiContextDTO(ip: '5.6.7.8'));
        self::assertInstanceOf(AuthorizationDTO::class, $auth);
        self::assertFalse($auth->allowed);
        self::assertSame('ip:default', $auth->reason);
    }
}
