<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\Service\Filter\FilterChain;
use Neox\FireGeolocatorBundle\Service\Filter\FilterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class FilterChainTest extends TestCase
{
    public function testStopsOnFirstDenialAndIgnoresNulls(): void
    {
        $req = new Request();
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $f1 = new class implements FilterInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
            {
                return null;
            }
        };
        $f2 = new class implements FilterInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
            {
                return new AuthorizationDTO(false, 'Denied by test', 'test');
            }
        };
        $f3 = new class implements FilterInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
            {
                return new AuthorizationDTO(true, 'allow explicit', 'ok');
            }
        };

        $chain = new FilterChain([$f1, $f2, $f3]);
        $res   = $chain->decide($req, $ctx);
        $this->assertInstanceOf(AuthorizationDTO::class, $res);
        $this->assertFalse($res->allowed);
        $this->assertSame('test', $res->blockingFilter);
    }

    public function testReturnsAllowWhenNoDenialOccurs(): void
    {
        $req = new Request();
        $ctx = new GeoApiContextDTO('1.2.3.4');

        $f1 = new class implements FilterInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
            {
                return null;
            }
        };
        $f2 = new class implements FilterInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
            {
                return new AuthorizationDTO(true, 'allow explicit', 'ok');
            }
        };

        $chain = new FilterChain([$f1, $f2]);
        $res   = $chain->decide($req, $ctx);
        $this->assertInstanceOf(AuthorizationDTO::class, $res);
        $this->assertTrue($res->allowed);
    }
}
