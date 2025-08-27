<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\Core\CrawlerFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CrawlerFilterTest extends TestCase
{
    private function makeRequestWithConfig(array $filters, string $ua): Request
    {
        $req = new Request();
        $req->headers->set('User-Agent', $ua);
        $cfg = new ResolvedGeoApiConfigDTO(filters: $filters);
        $req->attributes->set('geolocator_config', $cfg);

        return $req;
    }

    public function testKnownCrawlerAllowedWhenAllowKnownTrue(): void
    {
        $filters = [
            'crawler' => [
                'enabled'          => true,
                'default_behavior' => 'allow',
                'allow_known'      => true,
                'rules'            => [],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $filter = new CrawlerFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNull($res, 'Known crawler should be allowed when allow_known');
    }

    public function testBotLikeDeniedWhenAllowKnownFalseAndDefaultAllow(): void
    {
        $filters = [
            'crawler' => [
                'enabled'          => true,
                'default_behavior' => 'allow',
                'allow_known'      => false,
                'rules'            => [],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters, 'SomeGenericBot 1.0');
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $filter = new CrawlerFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed, 'Looks-like crawler should be denied when not allow_known');
    }

    public function testMinusRuleCanDenyEvenWhenKnownAndAllowKnownTrue(): void
    {
        $filters = [
            'crawler' => [
                'enabled'          => true,
                'default_behavior' => 'allow',
                'allow_known'      => true,
                'rules'            => ['-discordbot'],
            ],
        ];
        $req = $this->makeRequestWithConfig($filters, 'Discordbot/2.0');
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $filter = new CrawlerFilter();
        $res    = $filter->decide($req, $ctx);
        $this->assertNotNull($res);
        $this->assertFalse($res->allowed);
    }
}
