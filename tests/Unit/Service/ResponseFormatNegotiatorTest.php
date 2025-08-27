<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\Service\ResponseFormatNegotiator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ResponseFormatNegotiatorTest extends TestCase
{
    public function testWantsJsonByHeader(): void
    {
        $neg = new ResponseFormatNegotiator();
        $req = new Request();
        $req->headers->set('Accept', 'application/json');
        $this->assertTrue($neg->wantsJson($req));
    }

    public function testWantsJsonByXhr(): void
    {
        $neg = new ResponseFormatNegotiator();
        $req = new Request();
        $req->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->assertTrue($neg->wantsJson($req));
    }

    public function testNotJson(): void
    {
        $neg = new ResponseFormatNegotiator();
        $req = new Request();
        $this->assertFalse($neg->wantsJson($req));
    }

    public function testWantsProblemJson(): void
    {
        $neg = new ResponseFormatNegotiator();
        $req = new Request();
        $req->headers->set('Accept', 'application/problem+json');
        $this->assertTrue($neg->wantsProblemJson($req));
        $this->assertTrue($neg->wantsJson($req));
    }
}
