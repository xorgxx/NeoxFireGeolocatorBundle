<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\CountryFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CountryFilterTest extends TestCase
{
    private function makeRequestWithConfig(array $filters): Request
    {
        $req = new Request();
        $cfg = new ResolvedGeoApiConfigDTO(filters: $filters);
        $req->attributes->set('geolocator_config', $cfg);

        return $req;
    }

    public function testMinusRuleDeniesWhenCountryMatches(): void
    {
        $filters = [
            'country' => [
                'default_behavior' => 'allow',
                'rules'            => ['-FR'],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters);
        $ctx = new GeoApiContextDTO('1.2.3.4', countryCode: 'FR');

        $filter = new CountryFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }

    public function testDefaultBlockWithoutPlusMatchDenies(): void
    {
        $filters = [
            'country' => [
                'default_behavior' => 'block',
                'rules'            => [],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters);
        $ctx = new GeoApiContextDTO('1.2.3.4', countryCode: 'DE');

        $filter = new CountryFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }

    public function testPlusRuleAllowsUnderDefaultBlock(): void
    {
        $filters = [
            'country' => [
                'default_behavior' => 'block',
                'rules'            => ['+DE'],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters);
        $ctx = new GeoApiContextDTO('1.2.3.4', countryCode: 'DE');

        $filter = new CountryFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNull($res, 'Allow path should return null (no explicit allow)');
    }
}
