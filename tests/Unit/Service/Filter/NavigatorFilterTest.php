<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\NavigatorFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NavigatorFilterTest extends TestCase
{
    private function makeRequestWithConfig(array $filters, string $ua): Request
    {
        $req = new Request();
        $req->headers->set('User-Agent', $ua);
        $cfg = new ResolvedGeoApiConfigDTO(filters: $filters);
        $req->attributes->set('geolocator_config', $cfg);

        return $req;
    }

    public function testMinusRuleDenies(): void
    {
        $filters = [
            'navigator' => [
                'default_behavior' => 'allow',
                'rules'            => ['-chrome'],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters, 'Mozilla Chrome/120 Safari');
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $filter = new NavigatorFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }

    public function testDefaultBlockWithoutPlusMatchDenies(): void
    {
        $filters = [
            'navigator' => [
                'default_behavior' => 'block',
                'rules'            => [],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters, 'Mozilla Firefox');
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $filter = new NavigatorFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }
}
