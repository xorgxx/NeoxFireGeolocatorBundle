<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\Service\GeoRuleEvaluator;
use PHPUnit\Framework\TestCase;

class GeoRuleEvaluatorTest extends TestCase
{
    public function testAllowByDefault(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'navigator' => ['default_behavior' => 'allow', 'rules' => []],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false]);
        $this->assertTrue($auth->allowed);
    }

    public function testDenyOnCountryRule(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'country' => ['default_behavior' => 'allow', 'rules' => ['-FR']],
        ], ['ua' => 'Mozilla', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false]);

        $this->assertFalse($auth->allowed);
        $this->assertStringContainsString('country', (string) $auth->reason);
    }

    public function testIpCidrMatchV4(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'ip' => ['default_behavior' => 'allow', 'rules' => ['-10.0.0.0/8']],
        ], ['ua' => 'Mozilla', 'ip' => '10.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false]);

        $this->assertFalse($auth->allowed);
    }

    public function testNavigatorAllowButCrawlerDenyTakesPrecedence(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'navigator' => ['default_behavior' => 'deny', 'rules' => ['+chrome']],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => ['-discordbot']],
        ], ['ua' => 'Chrome', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => true]);

        $this->assertFalse($auth->allowed);
    }

    public function testVpnBlockWhenDetectedAndDefaultBlock(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'vpn'       => ['enabled' => true, 'default_behavior' => 'block'],
            'navigator' => ['default_behavior' => 'allow', 'rules' => []],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false, 'isVpn' => true]);
        $this->assertFalse($auth->allowed);
        $this->assertStringContainsString('vpn', (string) $auth->reason);
    }

    public function testVpnAllowWhenDetectedAndDefaultAllow(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'vpn'       => ['enabled' => true, 'default_behavior' => 'allow'],
            'navigator' => ['default_behavior' => 'allow', 'rules' => []],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false, 'isVpn' => true]);
        $this->assertTrue($auth->allowed);
    }

    public function testVpnIgnoredWhenNotDetectedEvenIfBlock(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'vpn'       => ['enabled' => true, 'default_behavior' => 'block'],
            'navigator' => ['default_behavior' => 'allow', 'rules' => []],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false, 'isVpn' => false]);
        $this->assertTrue($auth->allowed);
    }

    public function testIpv6CidrMatchAndDenies(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'ip'        => ['default_behavior' => 'allow', 'rules' => ['-2001:db8::/32']],
            'navigator' => ['default_behavior' => 'allow', 'rules' => []],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla', 'ip' => '2001:db8::1', 'countryCode' => 'FR', 'isCrawler' => false]);
        $this->assertFalse($auth->allowed);
    }

    public function testIpv6WhitelistPrecedenceAllows(): void
    {
        $ev   = new GeoRuleEvaluator();
        $auth = $ev->evaluate([
            'ip'        => ['default_behavior' => 'block', 'rules' => ['+2001:db8::1', '-2001:db8::/32']],
            'navigator' => ['default_behavior' => 'allow', 'rules' => []],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla', 'ip' => '2001:db8::1', 'countryCode' => 'FR', 'isCrawler' => false]);
        $this->assertTrue($auth->allowed);
    }

    public function testNavigatorRegexAndWordBoundary(): void
    {
        $ev = new GeoRuleEvaluator();
        // Regex allow Chrome versions, default deny to ensure the regex grants access
        $authRegex = $ev->evaluate([
            'navigator' => ['default_behavior' => 'block', 'rules' => ['+/Chrome\\/\d+/i']],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Mozilla Chrome/120 Safari', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false]);
        $this->assertTrue($authRegex->allowed);

        // Word boundary should match Edge but not edger
        $authEdge = $ev->evaluate([
            'navigator' => ['default_behavior' => 'block', 'rules' => ['+edge']],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'Microsoft Edge', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false]);
        $this->assertTrue($authEdge->allowed);

        $authEdger = $ev->evaluate([
            'navigator' => ['default_behavior' => 'block', 'rules' => ['+edge']],
            'country'   => ['default_behavior' => 'allow', 'rules' => []],
            'ip'        => ['default_behavior' => 'allow', 'rules' => []],
            'crawler'   => ['default_behavior' => 'allow', 'rules' => []],
        ], ['ua' => 'edger chrome', 'ip' => '1.2.3.4', 'countryCode' => 'FR', 'isCrawler' => false]);
        $this->assertFalse($authEdger->allowed);
    }
}
