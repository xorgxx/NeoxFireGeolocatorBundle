<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\Service\Net\IpUtils;
use PHPUnit\Framework\TestCase;

final class IpUtilsTest extends TestCase
{
    public function testPickFirstPublicIpFromMixedChain(): void
    {
        $header = '203.0.113.5, 10.0.0.1';
        $this->assertSame('203.0.113.5', IpUtils::pickClientIpFromForwarded($header));
    }

    public function testPickIpv6OverPrivateIpv4(): void
    {
        $header = '10.0.0.1, 2001:db8::1';
        $this->assertSame('2001:db8::1', IpUtils::pickClientIpFromForwarded($header));
    }

    public function testBracketedAndQuotedTokensAreHandled(): void
    {
        $header = ' [2001:db8::1] ; "10.0.0.1" ';
        $this->assertSame('2001:db8::1', IpUtils::pickClientIpFromForwarded($header));
    }

    public function testOnlyPrivateIpsReturnsFirstValid(): void
    {
        $header = '::1, 127.0.0.1';
        $this->assertSame('::1', IpUtils::pickClientIpFromForwarded($header));
    }
}
