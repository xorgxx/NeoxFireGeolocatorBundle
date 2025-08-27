<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\Service\ResponseFactory;
use Neox\FireGeolocatorBundle\Service\ResponseFormatNegotiator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ResponseFactoryTest extends TestCase
{
    private function factory(Request $req): ResponseFactory
    {
        $twig = new Environment(new ArrayLoader([
            '@Geolocator/deny.html.twig'   => '<p>deny</p>',
            '@Geolocator/banned.html.twig' => '<p>banned</p>',
        ]));
        $neg = new ResponseFormatNegotiator();
        $rs  = new RequestStack();
        $rs->push($req);

        return new ResponseFactory($twig, $neg, $rs);
    }

    public function testJsonDenied(): void
    {
        $req = new Request();
        $req->headers->set('Accept', 'application/json');
        $factory = $this->factory($req);
        $resp    = $factory->denied(null, new AuthorizationDTO(false, 'nope', 'rule'), new GeoApiContextDTO('1.2.3.4'));
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('application/json', $resp->headers->get('Content-Type'));
        $this->assertStringContainsString('Accept, X-Requested-With', $resp->headers->get('Vary'));
    }

    public function testHtmlBanned(): void
    {
        $req     = new Request();
        $factory = $this->factory($req);
        $resp    = $factory->banned(null, null);
        $this->assertSame(429, $resp->getStatusCode());
        $this->assertStringContainsString('banned', (string) $resp->getContent());
    }
}
